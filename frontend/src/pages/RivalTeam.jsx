import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import apiClient from '../api/client'
import Sparkline from '../components/Sparkline'
import { TrendingUpIcon, WalletIcon, ChevronDownIcon } from '../components/Icons'
import { formatMoneyM, formatPercent, POSITION_LABELS, initials, ACTION_LABELS } from '../utils/format'

// Rival players are only ever analyzable through their clause (never
// HOLD/SELL — that's an owner-only decision) — same 4-tier scale
// Clauses.jsx already uses for ClauseEconomicAnalysisService's verdict.
const ACTION_BADGE = {
  PAY_CLAUSE: 'bg-info/15 text-info',
  CONSIDER: 'bg-bull/15 text-bull',
  WAIT: 'bg-warn/15 text-warn',
  DO_NOT_PAY: 'bg-bear/15 text-bear',
}

function scoreBadgeClass(score) {
  if (score >= 80) return 'bg-bull/15 text-bull'
  if (score >= 65) return 'bg-primary/15 text-primary'
  return 'bg-warn/15 text-warn'
}

function ScoreStat({ label, value, colorClass }) {
  return (
    <div className="text-center">
      <p className={`text-lg font-bold ${colorClass}`}>{value}</p>
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
    </div>
  )
}

function PlayerRow({ p }) {
  const [open, setOpen] = useState(false)
  const d = p.decision
  const raw = d?.raw

  return (
    <>
      <tr className="border-b border-border last:border-0 hover:bg-surface-raised">
        <td className="px-4 py-3">
          <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-primary">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
              {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium">{p.name}</p>
              <p className="truncate text-xs text-muted-foreground">{p.club}</p>
            </div>
          </Link>
        </td>
        <td className="px-4 py-3">
          <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
            {POSITION_LABELS[p.position] || p.position}
          </span>
        </td>
        <td className="px-4 py-3 font-medium">{formatMoneyM(p.marketValue)}</td>
        <td className="px-4 py-3">{p.clauseValue ? formatMoneyM(p.clauseValue) : '—'}</td>
        <td
          className="w-24 px-4 py-3"
          title={
            p.historySource === 'external'
              ? "Línia reconstruïda amb dades de futbolfantasy.com (no oficial) — encara no tenim prou historial propi"
              : undefined
          }
        >
          <Sparkline data={p.history} />
          {p.historySource === 'external' && <span className="text-[9px] text-muted-foreground">*ext.</span>}
        </td>
        <td className={`px-4 py-3 ${p.externalTrend?.pct7d > 0 ? 'text-bull' : p.externalTrend?.pct7d < 0 ? 'text-bear' : 'text-muted-foreground'}`}>
          {p.externalTrend?.pct7d != null
            ? `${p.externalTrend.pct7d >= 0 ? '↗' : '↘'} ${formatPercent(p.externalTrend.pct7d)}`
            : '—'}
        </td>
        <td className="px-4 py-3">{p.averagePoints?.toFixed(1)}</td>
        <td className="px-4 py-3">
          <span className={`rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(p.fantasyScore)}`}>
            {Math.round(p.fantasyScore)}
          </span>
        </td>
        <td className="px-4 py-3">
          {d ? (
            <div className="flex items-start gap-1">
              <div>
                <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[d.action]}`}>
                  {ACTION_LABELS[d.action] || d.action}
                </span>
                {d.confidence != null && <p className="mt-1 text-[10px] text-muted-foreground">Confiança {d.confidence}%</p>}
              </div>
              <button
                onClick={() => setOpen((o) => !o)}
                className="mt-0.5 shrink-0 rounded p-0.5 text-muted-foreground hover:text-primary"
                title="Veure com s'ha calculat"
              >
                <ChevronDownIcon className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
              </button>
            </div>
          ) : (
            <span className="text-xs text-muted-foreground" title="No es coneix la clàusula d'aquest jugador">
              —
            </span>
          )}
        </td>
      </tr>
      {open && raw && (
        <tr className="border-b border-border bg-background/40 last:border-0">
          <td colSpan={9} className="px-4 py-4">
            <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Anàlisi econòmica de la clàusula
                </p>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                  <span>
                    Prima: <span className="font-semibold text-foreground">{formatPercent(raw.clausePremiumPct * 100)}</span>
                  </span>
                  <span>
                    Break-even: <span className="font-semibold text-foreground">{raw.breakEvenDays != null ? `${raw.breakEvenDays} dies` : '—'}</span>
                  </span>
                  {raw.isLocked && (
                    <span>
                      Desbloqueig: <span className="font-semibold text-foreground">en {raw.daysUntilUnlock} dies</span>
                    </span>
                  )}
                </div>
              </div>
              <div className="flex shrink-0 gap-5 border-t border-border pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
                <ScoreStat label="Score clàusula" value={d.mainScore} colorClass="text-info" />
                <ScoreStat label="ROI 14d" value={formatPercent(raw.roi14d * 100)} colorClass="text-primary" />
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

function PlayerCard({ p }) {
  const [open, setOpen] = useState(false)
  const d = p.decision
  const raw = d?.raw

  return (
    <div className="panel p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-border-strong">
      <button onClick={() => setOpen((o) => !o)} className="flex w-full items-start justify-between gap-3 text-left">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
            {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold">{p.name}</p>
            <p className="truncate text-xs text-muted-foreground">
              {p.club} · {POSITION_LABELS[p.position] || p.position}
            </p>
          </div>
        </div>
        <span className={`num shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${scoreBadgeClass(p.fantasyScore)}`}>
          {Math.round(p.fantasyScore)}
        </span>
      </button>

      <div className="mt-3 flex items-end justify-between gap-3">
        <div>
          <p className="num text-2xl font-semibold">{formatMoneyM(p.marketValue)}</p>
          <p className="num mt-1 text-xs text-muted-foreground">Clàusula {p.clauseValue ? formatMoneyM(p.clauseValue) : '—'}</p>
        </div>
        {p.history?.length > 1 && (
          <div className="h-8 w-24 shrink-0">
            <Sparkline data={p.history} />
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3">
        <span className="num text-xs text-muted-foreground">mitjana {p.averagePoints?.toFixed(1)}</span>
        {d ? (
          <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[d.action]}`}>
            {ACTION_LABELS[d.action] || d.action}
          </span>
        ) : (
          <span className="text-xs text-muted-foreground">—</span>
        )}
      </div>

      {open && raw && (
        <div className="mt-3 border-t border-border pt-3">
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span>
              Prima: <span className="font-semibold text-foreground">{formatPercent(raw.clausePremiumPct * 100)}</span>
            </span>
            <span>
              Break-even: <span className="font-semibold text-foreground">{raw.breakEvenDays != null ? `${raw.breakEvenDays} dies` : '—'}</span>
            </span>
            {raw.isLocked && (
              <span>
                Desbloqueig: <span className="font-semibold text-foreground">en {raw.daysUntilUnlock} dies</span>
              </span>
            )}
          </div>
          <div className="mt-3 flex gap-5">
            <ScoreStat label="Score clàusula" value={d.mainScore} colorClass="text-info" />
            <ScoreStat label="ROI 14d" value={formatPercent(raw.roi14d * 100)} colorClass="text-primary" />
          </div>
        </div>
      )}
    </div>
  )
}

function StatTile({ icon: Icon, label, value, hint, accent }) {
  return (
    <div className="panel p-5">
      <div className="flex items-center justify-between">
        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
        <Icon className={`h-4 w-4 ${accent ? 'text-' + accent : 'text-muted-foreground'}`} />
      </div>
      <p className="num mt-3 text-2xl font-semibold">{value}</p>
      {hint && <p className="mt-2 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

export default function RivalTeam() {
  const { teamId } = useParams()
  const [team, setTeam] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    setTeam(null)
    setError('')
    apiClient
      .get(`/standings/${teamId}`)
      .then((res) => setTeam(res.data))
      .catch((err) => setError(err.response?.data?.message || "No s'ha pogut carregar aquest equip."))
  }, [teamId])

  if (error) return <p className="text-bear">{error}</p>
  if (!team) return <p className="text-muted-foreground">Carregant…</p>

  return (
    <div>
      <Link to="/standings" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
        ← Classificació
      </Link>

      <h1 className="mt-4 text-2xl font-bold tracking-tight">{team.team.name}</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {team.team.managerName ? `${team.team.managerName} · ` : ''}
        {team.players.length} jugadors
      </p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatTile icon={TrendingUpIcon} label="Valor plantilla" value={formatMoneyM(team.summary?.teamValue)} accent="bull" />
        <StatTile
          icon={WalletIcon}
          label="Saldo"
          value="—"
          hint="No disponible — LaLiga només exposa el saldo del teu propi equip"
        />
      </div>

      <p className="mt-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm text-muted-foreground">
        Aquest no és el teu equip: aquí només es mostra si val la pena pagar-li la clàusula a cada jugador (com a
        /clàusules), mai un consell de mantenir o vendre — això només té sentit per als teus propis jugadors.
      </p>

      <div className="mt-6 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-220 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th className="px-4 py-3">Jugador</th>
              <th className="px-4 py-3">Pos.</th>
              <th className="px-4 py-3">Valor</th>
              <th className="px-4 py-3">Clàusula</th>
              <th className="px-4 py-3">7D</th>
              <th className="px-4 py-3" title="Font externa (futbolfantasy.com, no oficial)">
                7D ext.*
              </th>
              <th className="px-4 py-3">Mitjana</th>
              <th className="px-4 py-3">Score</th>
              <th className="px-4 py-3">
                Clàusula <span className="normal-case text-muted-foreground">(desplega per veure el càlcul)</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {team.players.map((p) => (
              <PlayerRow key={p.id} p={p} />
            ))}
          </tbody>
        </table>
      </div>

      <div className="mt-6 space-y-3 md:hidden">
        {team.players.map((p) => (
          <PlayerCard key={p.id} p={p} />
        ))}
      </div>
    </div>
  )
}
