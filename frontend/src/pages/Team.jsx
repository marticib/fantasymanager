import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import Sparkline from '../components/Sparkline'
import { TrendingUpIcon, WalletIcon, LinkIcon, ChevronDownIcon } from '../components/Icons'
import { formatMoneyM, formatPercent, POSITION_LABELS, initials } from '../utils/format'

const ACTION_BADGE = {
  SELL: 'bg-sell/15 text-sell',
  HOLD: 'bg-hold/15 text-hold',
  LOCK_CLAUSE: 'bg-clause/15 text-clause',
}

const ACTION_LABEL = { SELL: 'VENDRE', HOLD: 'MANTENIR', LOCK_CLAUSE: 'PUJAR CLÀUSULA' }

const SELL_REASON_LABEL = {
  TRADE_PROFIT: '💰 Per benefici',
  DEPRECIATION: '📉 Per depreciació',
  LIQUIDITY_NEED: '💧 Per liquiditat',
  CAPITAL_REALLOCATION: '🔁 Reassignar capital',
  LOW_PERFORMANCE: '⚠️ Rendiment baix',
}

function scoreBadgeClass(score) {
  if (score >= 80) return 'bg-buy/15 text-buy'
  if (score >= 65) return 'bg-accent/15 text-accent'
  return 'bg-trading/15 text-trading'
}

function ScoreStat({ label, value, colorClass }) {
  return (
    <div className="text-center">
      <p className={`text-lg font-bold ${colorClass}`}>{value}</p>
      <p className="text-[10px] uppercase tracking-wide text-text-muted">{label}</p>
    </div>
  )
}

function PlayerRow({ p }) {
  const [open, setOpen] = useState(false)
  const d = p.decision

  return (
    <>
      <tr
        className={`border-b border-border last:border-0 hover:bg-surface-hover ${
          p.action === 'SELL' ? 'bg-sell/5' : p.action === 'LOCK_CLAUSE' ? 'bg-clause/5' : ''
        }`}
      >
        <td className="px-4 py-3">
          <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-accent">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-hold/15 text-[10px] font-bold text-hold">
              {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium">{p.name}</p>
              <p className="truncate text-xs text-text-muted">{p.club}</p>
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
          {p.historySource === 'external' && <span className="text-[9px] text-text-muted">*ext.</span>}
        </td>
        <td className={`px-4 py-3 ${p.externalTrend?.pct7d > 0 ? 'text-buy' : p.externalTrend?.pct7d < 0 ? 'text-sell' : 'text-text-muted'}`}>
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
          <div className="flex items-start gap-1">
            <div>
              <span
                className={`inline-flex cursor-help rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[p.action]}`}
                title={d?.reason}
              >
                {ACTION_LABEL[p.action]}
              </span>
              {d?.confidence != null && <p className="mt-1 text-[10px] text-text-muted">Confiança {d.confidence}%</p>}
              {p.action === 'SELL' && d?.sellReasonCode && (
                <p className="mt-0.5 text-[10px] text-text-muted">{SELL_REASON_LABEL[d.sellReasonCode]}</p>
              )}
              {p.action === 'HOLD' && d?.clauseTiming?.shouldRaise && !d.clauseTiming.shouldRaiseNow && (
                <p className="mt-0.5 text-[10px] text-clause" title="El Clause Score és alt, però encara no és el moment de gastar-hi diners.">
                  🔒 Pujar clàusula
                  {d.clauseTiming.daysRemaining != null && ` en ${d.clauseTiming.daysRemaining}d`}
                </p>
              )}
              {p.action === 'LOCK_CLAUSE' && (
                <p className="mt-0.5 text-[10px] text-clause">🔴 Avui — protecció a punt d'acabar</p>
              )}
            </div>
            {d?.reason && (
              <button
                onClick={() => setOpen((o) => !o)}
                className="mt-0.5 shrink-0 rounded p-0.5 text-text-muted hover:text-accent"
                title="Veure com s'ha calculat"
              >
                <ChevronDownIcon className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
              </button>
            )}
          </div>
        </td>
      </tr>
      {open && d && (
        <tr className="border-b border-border bg-bg/40 last:border-0">
          <td colSpan={9} className="px-4 py-4">
            <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-text-muted">
                  Com s'ha calculat "{ACTION_LABEL[p.action]}"
                </p>
                <p className="mt-1.5 whitespace-pre-line text-sm text-text">{d.reason}</p>
              </div>
              <div className="flex shrink-0 gap-5 border-t border-border pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
                <ScoreStat label="Mantenir" value={d.holdScore} colorClass="text-hold" />
                <ScoreStat label="Vendre" value={d.adjustedSellScore} colorClass="text-sell" />
                <ScoreStat label="Clàusula" value={d.clauseScore} colorClass="text-clause" />
                <ScoreStat label="Trade" value={d.tradeScore} colorClass="text-accent" />
              </div>
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

function StatTile({ icon: Icon, label, value, hint, accent }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-text-muted">{label}</p>
        <Icon className={`h-4 w-4 ${accent ? 'text-' + accent : 'text-text-muted'}`} />
      </div>
      <p className="mt-3 text-2xl font-bold">{value}</p>
      {hint && <p className="mt-2 text-xs text-text-muted">{hint}</p>}
    </div>
  )
}

export default function Team() {
  const [team, setTeam] = useState(null)
  const [analysis, setAnalysis] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    apiClient
      .get('/team')
      .then((res) => setTeam(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant la plantilla.'))
    apiClient.get('/team/analysis').then((res) => setAnalysis(res.data)).catch(() => {})
  }, [])

  if (error) return <p className="text-sell">{error}</p>
  if (!team) return <p className="text-text-muted">Carregant…</p>

  const sellCount = team.players.filter((p) => p.action === 'SELL').length
  const lockClauseCount = team.players.filter((p) => p.action === 'LOCK_CLAUSE').length

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">La meva plantilla</h1>
      <p className="mt-1 text-sm text-text-muted">{team.players.length} jugadors</p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatTile icon={TrendingUpIcon} label="Valor plantilla" value={formatMoneyM(team.summary?.teamValue)} accent="buy" />
        <StatTile icon={WalletIcon} label="Saldo" value={formatMoneyM(team.summary?.cash)} hint="Disponible per operar" />
        <StatTile
          icon={LinkIcon}
          label="Capital disponible"
          value={formatMoneyM(team.summary?.availableCapital)}
          hint={`Després de la reserva mínima (${formatMoneyM(team.summary?.minimumCashReserve)})`}
          accent="clause"
        />
      </div>

      {analysis && (
        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          {Object.entries(analysis.byPosition).map(([pos, stats]) => (
            <div key={pos} className="rounded-2xl border border-border bg-surface p-4">
              <p className="text-xs text-text-muted">{POSITION_LABELS[pos] || pos}</p>
              <p className="mt-1 text-xl font-bold">{stats.count}</p>
              <p className="text-xs text-text-muted">Score mitjà {stats.averageScore ?? '—'}</p>
            </div>
          ))}
        </div>
      )}

      {analysis?.weakPositions?.length > 0 && (
        <p className="mt-4 rounded-xl border border-trading/30 bg-trading/10 px-4 py-3 text-sm text-trading">
          Prioritat alta: reforça {analysis.weakPositions.map((p) => POSITION_LABELS[p] || p).join(', ')} — menys de 2
          jugadors amb Fantasy Score &gt; 70.
        </p>
      )}

      {sellCount > 0 && (
        <p className="mt-4 rounded-xl border border-sell/30 bg-sell/10 px-4 py-3 text-sm text-sell">
          {sellCount} {sellCount === 1 ? 'jugador amb senyal de venda' : 'jugadors amb senyal de venda'} — mira la
          columna Acció a la taula.
        </p>
      )}

      {lockClauseCount > 0 && (
        <p className="mt-4 rounded-xl border border-clause/30 bg-clause/10 px-4 py-3 text-sm text-clause">
          {lockClauseCount} {lockClauseCount === 1 ? 'jugador amb recomanació de pujar la clàusula' : 'jugadors amb recomanació de pujar la clàusula'}{' '}
          — mira la columna Acció a la taula.
        </p>
      )}

      <div className="mt-6 overflow-x-auto rounded-2xl border border-border bg-surface">
        <table className="w-full min-w-220 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-text-muted">
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
                Acció <span className="normal-case text-text-muted">(desplega per veure el càlcul)</span>
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
    </div>
  )
}
