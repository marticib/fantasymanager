import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import apiClient from '../api/client'
import Sparkline from '../components/Sparkline'
import { TrendingUpIcon, WalletIcon, ChevronDownIcon } from '../components/Icons'
import { formatDelta, formatFullDate, formatMoneyM, formatPercent, POSITION_LABELS, initials, ACTION_LABELS } from '../utils/format'
import ChangeBadge from '../components/ChangeBadge'
import CountUp from '../components/CountUp'
import { PageSkeleton, ErrorState } from '../components/PageState'
import InfoTip from '../components/InfoTip'

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

/** 24h value move: our own history first, futbolfantasy's 1-day figure (flagged *ext.) when we have none yet. */
function player24hDelta(p) {
  const own = p.trend?.change24h
  return { eurDelta: own ?? p.externalTrend?.delta1d ?? null, isOwn: own != null }
}

function Delta24h({ p }) {
  const { eurDelta, isOwn } = player24hDelta(p)
  if (eurDelta == null) return <span className="text-muted-foreground">—</span>
  return (
    <span className={`num whitespace-nowrap ${eurDelta > 0 ? 'text-bull' : eurDelta < 0 ? 'text-bear' : 'text-muted-foreground'}`}>
      {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
      {!isOwn && <span className="text-[11px] text-muted-foreground"> *ext.</span>}
    </span>
  )
}

function ScoreStat({ label, value, colorClass }) {
  return (
    <div className="text-center">
      <p className={`text-lg font-bold ${colorClass}`}>{value}</p>
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
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
          <Delta24h p={p} />
        </td>
        <td className="px-4 py-3">
          <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-primary">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[11px] font-bold text-muted-foreground">
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
          {p.historySource === 'external' && <span className="text-[11px] text-muted-foreground">*ext.</span>}
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
                {d.confidence != null && <p className="mt-1 text-[11px] text-muted-foreground">Confiança {d.confidence}%</p>}
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
          <td colSpan={10} className="px-4 py-4">
            <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Anàlisi econòmica de la clàusula
                </p>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                  <span>
                    Sobrecost: <span className="font-semibold text-foreground">{formatPercent(raw.clausePremiumPct * 100)}</span>
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
                <ScoreStat label="Rendiment 14d" value={formatPercent(raw.roi14d * 100)} colorClass="text-primary" />
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
          <p className="mt-1 text-xs">
            <Delta24h p={p} /> <span className="text-muted-foreground">en 24h</span>
          </p>
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
              Sobrecost: <span className="font-semibold text-foreground">{formatPercent(raw.clausePremiumPct * 100)}</span>
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
            <ScoreStat label="Rendiment 14d" value={formatPercent(raw.roi14d * 100)} colorClass="text-primary" />
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

  if (error) return <ErrorState message={error} />
  if (!team) return <PageSkeleton cards={3} rows={8} />

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

      <div className="stagger mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <StatTile
          icon={TrendingUpIcon}
          label="Valor plantilla"
          value={<CountUp value={team.summary?.teamValue} format={formatMoneyM} />}
          hint={
            team.summary?.teamValueDelta24h != null ? (
              <>
                <ChangeBadge change={team.summary.teamValueDelta24h} changePct={team.summary.teamValueDelta24hPct} /> en 24h
              </>
            ) : (
              'Encara no hi ha prou historial per calcular la variació de 24h.'
            )
          }
          accent="bull"
        />
        <StatTile
          icon={WalletIcon}
          label="Saldo"
          value="—"
          hint="No disponible — LaLiga només exposa el saldo del teu propi equip"
        />
      </div>

      <p className="mt-4 panel px-4 py-3 text-sm text-muted-foreground">
        Aquest no és el teu equip: aquí només es mostra si val la pena pagar-li la clàusula a cada jugador (com a
        /clàusules), mai un consell de mantenir o vendre — això només té sentit per als teus propis jugadors.
      </p>

      <div className="mt-6 hidden overflow-x-auto panel md:block">
        <table className="w-full min-w-240 text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  24H <InfoTip>Quant ha pujat o baixat el valor de mercat del jugador en les últimes 24 hores.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">Jugador</th>
              <th className="px-4 py-3">Pos.</th>
              <th className="px-4 py-3">Valor</th>
              <th className="px-4 py-3">Clàusula</th>
              <th className="px-4 py-3">7D</th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  7D ext.* <InfoTip>Variació del valor en 7 dies segons futbolfantasy.com (font externa, no oficial).</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">Mitjana</th>
              <th className="px-4 py-3">Puntuació</th>
              <th className="px-4 py-3">
                Clàusula <span className="normal-case text-muted-foreground">(desplega per veure el càlcul)</span>
              </th>
            </tr>
          </thead>
          <tbody className="stagger-rows">
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

      {team.daily?.some((d) => d.change !== null) && (
        <div className="mt-6 panel p-5">
          <h2 className="font-semibold">Guany diari</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Quant ha guanyat o perdut cada dia la seva plantilla actual pel canvi de valor dels jugadors (els fitxatges i vendes no compten).
          </p>
          <div className="stagger mt-3 divide-y divide-border">
            {[...team.daily].reverse().map((row) => (
              <div key={row.day} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span className="capitalize text-muted-foreground">{formatFullDate(new Date(`${row.day}T12:00:00`))}</span>
                <div className="flex items-center gap-3">
                  <span className="num font-medium">{formatMoneyM(row.value)}</span>
                  <ChangeBadge change={row.change} changePct={row.changePct} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
