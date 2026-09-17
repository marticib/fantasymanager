import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import ChangeBadge from '../components/ChangeBadge'
import Sparkline from '../components/Sparkline'
import { TrendingUp, Wallet, Link2, ChevronDown } from 'lucide-react'
import { formatMoneyM, formatPercent, formatDelta, formatFullDate, POSITION_LABELS, initials } from '../utils/format'

const ACTION_BADGE = {
  SELL: 'bg-bear-soft text-bear',
  HOLD: 'bg-surface-raised text-muted-foreground',
  LOCK_CLAUSE: 'bg-info-soft text-info',
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
  if (score >= 80) return 'bg-bull-soft text-bull'
  if (score >= 65) return 'bg-primary/15 text-primary'
  return 'bg-warn-soft text-warn'
}

function eyebrow(text) {
  return <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{text}</p>
}

function player24hDelta(p) {
  const own = p.trend?.change24h
  const eurDelta = own ?? p.externalTrend?.delta1d
  return { eurDelta, isOwn: own != null }
}

function ScoreStat({ label, value, colorClass }) {
  return (
    <div className="text-center">
      <p className={`num text-lg font-bold ${colorClass}`}>{value}</p>
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
    </div>
  )
}

function DecisionDetail({ p }) {
  const d = p.decision

  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
      <div>
        {eyebrow(`Com s'ha calculat "${ACTION_LABEL[p.action]}"`)}
        <p className="mt-1.5 whitespace-pre-line text-sm text-foreground">{d.reason}</p>
      </div>
      <div className="flex shrink-0 gap-5 border-t border-border pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
        <ScoreStat label="Mantenir" value={d.holdScore} colorClass="text-muted-foreground" />
        <ScoreStat label="Vendre" value={d.adjustedSellScore} colorClass="text-bear" />
        <ScoreStat label="Clàusula" value={d.clauseScore} colorClass="text-info" />
        <ScoreStat label="Trade" value={d.tradeScore} colorClass="text-primary" />
      </div>
    </div>
  )
}

function ActionHint({ p }) {
  const d = p.decision

  return (
    <>
      {d?.confidence != null && <p className="mt-1 text-[10px] text-muted-foreground">Confiança {d.confidence}%</p>}
      {p.action === 'SELL' && d?.sellReasonCode && (
        <p className="mt-0.5 text-[10px] text-muted-foreground">{SELL_REASON_LABEL[d.sellReasonCode]}</p>
      )}
      {p.action === 'HOLD' && d?.clauseTiming?.shouldRaise && !d.clauseTiming.shouldRaiseNow && (
        <p className="mt-0.5 text-[10px] text-info" title="El Clause Score és alt, però encara no és el moment de gastar-hi diners.">
          🔒 Pujar clàusula
          {d.clauseTiming.daysRemaining != null && ` en ${d.clauseTiming.daysRemaining}d`}
        </p>
      )}
      {p.action === 'LOCK_CLAUSE' && <p className="mt-0.5 text-[10px] text-info">🔴 Avui — protecció a punt d'acabar</p>}
    </>
  )
}

function PlayerRow({ p }) {
  const [open, setOpen] = useState(false)
  const d = p.decision
  const { eurDelta, isOwn } = player24hDelta(p)

  return (
    <>
      <tr
        className={`border-b border-border last:border-0 hover:bg-surface-raised ${
          p.action === 'SELL' ? 'bg-bear/5' : p.action === 'LOCK_CLAUSE' ? 'bg-info/5' : ''
        }`}
      >
        <td className="px-4 py-2.5">
          {eurDelta == null ? (
            <span className="text-muted-foreground">—</span>
          ) : (
            <span
              className={`num ${eurDelta > 0 ? 'text-bull' : eurDelta < 0 ? 'text-bear' : 'text-muted-foreground'}`}
              title={!isOwn ? 'Font externa (futbolfantasy.com, no oficial) — encara no tenim prou historial propi' : undefined}
            >
              {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
              {!isOwn && <span className="text-[9px] text-muted-foreground"> *ext.</span>}
            </span>
          )}
        </td>
        <td className="px-4 py-2.5">
          <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-primary">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-[10px] font-bold text-muted-foreground">
              {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium">{p.name}</p>
              <p className="truncate text-xs text-muted-foreground">{p.club}</p>
            </div>
          </Link>
        </td>
        <td className="px-4 py-2.5">
          <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
            {POSITION_LABELS[p.position] || p.position}
          </span>
        </td>
        <td className="num px-4 py-2.5 font-medium">{formatMoneyM(p.marketValue)}</td>
        <td className="num px-4 py-2.5">{p.clauseValue ? formatMoneyM(p.clauseValue) : '—'}</td>
        <td className={`num px-4 py-2.5 ${p.externalTrend?.pct7d > 0 ? 'text-bull' : p.externalTrend?.pct7d < 0 ? 'text-bear' : 'text-muted-foreground'}`}>
          {p.externalTrend?.pct7d != null
            ? `${p.externalTrend.pct7d >= 0 ? '↗' : '↘'} ${formatPercent(p.externalTrend.pct7d)}`
            : '—'}
        </td>
        <td className="num px-4 py-2.5">{p.averagePoints?.toFixed(1)}</td>
        <td className="px-4 py-2.5">
          <span className={`num inline-flex rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(p.fantasyScore)}`}>
            {Math.round(p.fantasyScore)}
          </span>
        </td>
        <td className="px-4 py-2.5">
          <div className="flex items-start gap-1">
            <div>
              <span className={`inline-flex cursor-help rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[p.action]}`} title={d?.reason}>
                {ACTION_LABEL[p.action]}
              </span>
              <ActionHint p={p} />
            </div>
            {d?.reason && (
              <button
                onClick={() => setOpen((o) => !o)}
                className="mt-0.5 shrink-0 rounded p-0.5 text-muted-foreground hover:text-primary"
                title="Veure com s'ha calculat"
              >
                <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
              </button>
            )}
          </div>
        </td>
      </tr>
      {open && d && (
        <tr className="border-b border-border bg-background/40 last:border-0">
          <td colSpan={9} className="px-4 py-4">
            <DecisionDetail p={p} />
          </td>
        </tr>
      )}
    </>
  )
}

function PlayerCard({ p }) {
  const [open, setOpen] = useState(false)
  const d = p.decision
  const { eurDelta, isOwn } = player24hDelta(p)

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
          {eurDelta == null ? (
            <span className="mt-1.5 inline-flex text-xs text-muted-foreground">—</span>
          ) : (
            <span
              className={`num mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                eurDelta > 0 ? 'bg-bull-soft text-bull' : eurDelta < 0 ? 'bg-bear-soft text-bear' : 'bg-surface-raised text-muted-foreground'
              }`}
            >
              {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
              {!isOwn && <span className="text-[9px] opacity-80"> *ext.</span>}
            </span>
          )}
        </div>
        {p.history?.length > 1 && (
          <div className="h-8 w-24 shrink-0">
            <Sparkline data={p.history} />
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3">
        <p className="num text-sm text-muted-foreground">
          {p.points ?? 0} pts · mitjana {p.averagePoints?.toFixed(1) ?? '—'}
        </p>
        <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[p.action]}`}>{ACTION_LABEL[p.action]}</span>
      </div>

      {open && (
        <div className="mt-3 border-t border-border pt-3">
          <ActionHint p={p} />
          {d?.reason && <DecisionDetail p={p} />}
        </div>
      )}
    </div>
  )
}

function StatTile({ icon: Icon, label, value, hint, accent }) {
  return (
    <div className="panel p-5">
      <div className="flex items-center justify-between">
        {eyebrow(label)}
        <Icon className={`h-4 w-4 ${accent ? 'text-' + accent : 'text-muted-foreground'}`} />
      </div>
      <p className="num mt-3 text-2xl font-semibold">{value}</p>
      {hint && <p className="mt-2 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

export default function Team() {
  const [team, setTeam] = useState(null)
  const [analysis, setAnalysis] = useState(null)
  const [error, setError] = useState('')
  const [dailyHistory, setDailyHistory] = useState(null)

  useEffect(() => {
    apiClient
      .get('/team')
      .then((res) => setTeam(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant la plantilla.'))
    apiClient.get('/team/analysis').then((res) => setAnalysis(res.data)).catch(() => {})
    apiClient
      .get('/history/team-value', { params: { range: '7d' } })
      .then((res) => setDailyHistory(res.data.data))
      .catch(() => {})
  }, [])

  if (error) return <p className="text-bear">{error}</p>
  if (!team) return <p className="text-muted-foreground">Carregant…</p>

  const sellCount = team.players.filter((p) => p.action === 'SELL').length
  const lockClauseCount = team.players.filter((p) => p.action === 'LOCK_CLAUSE').length

  return (
    <div>
      <h1 className="text-3xl font-semibold tracking-tight">La meva plantilla</h1>
      <p className="mt-1 text-sm text-muted-foreground">{team.players.length} jugadors</p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatTile
          icon={TrendingUp}
          label="Valor plantilla"
          value={formatMoneyM(team.summary?.teamValue)}
          hint={
            team.summary?.teamValueDelta24h != null ? (
              <>
                <ChangeBadge change={team.summary.teamValueDelta24h} changePct={team.summary.teamValueDelta24hPct} /> en 24h
              </>
            ) : null
          }
          accent="bull"
        />
        <StatTile icon={Wallet} label="Saldo" value={formatMoneyM(team.summary?.cash)} hint="Disponible per operar" />
        <StatTile
          icon={Link2}
          label="Capital disponible"
          value={formatMoneyM(team.summary?.availableCapital)}
          hint={`Després de la reserva mínima (${formatMoneyM(team.summary?.minimumCashReserve)})`}
          accent="info"
        />
      </div>

      {analysis && (
        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          {Object.entries(analysis.byPosition).map(([pos, stats]) => (
            <div key={pos} className="panel p-4">
              {eyebrow(POSITION_LABELS[pos] || pos)}
              <p className="num mt-1 text-xl font-semibold">{stats.count}</p>
              <p className="text-xs text-muted-foreground">Score mitjà {stats.averageScore ?? '—'}</p>
            </div>
          ))}
        </div>
      )}

      {analysis?.weakPositions?.length > 0 && (
        <p className="mt-4 rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-sm text-warn">
          Prioritat alta: reforça {analysis.weakPositions.map((p) => POSITION_LABELS[p] || p).join(', ')} — menys de 2
          jugadors amb Fantasy Score &gt; 70.
        </p>
      )}

      {sellCount > 0 && (
        <p className="mt-4 rounded-xl border border-bear/30 bg-bear-soft px-4 py-3 text-sm text-bear">
          {sellCount} {sellCount === 1 ? 'jugador amb senyal de venda' : 'jugadors amb senyal de venda'} — mira la
          columna Acció a la taula.
        </p>
      )}

      {lockClauseCount > 0 && (
        <p className="mt-4 rounded-xl border border-info/30 bg-info-soft px-4 py-3 text-sm text-info">
          {lockClauseCount} {lockClauseCount === 1 ? 'jugador amb recomanació de pujar la clàusula' : 'jugadors amb recomanació de pujar la clàusula'}{' '}
          — mira la columna Acció a la taula.
        </p>
      )}

      {/* Desktop: dense table. Mobile: one card per player (see PlayerCard). */}
      <div className="mt-6 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-220 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
              <th className="px-4 py-3">24H</th>
              <th className="px-4 py-3">Jugador</th>
              <th className="px-4 py-3">Pos.</th>
              <th className="px-4 py-3">Valor</th>
              <th className="px-4 py-3">Clàusula</th>
              <th className="px-4 py-3" title="Font externa (futbolfantasy.com, no oficial)">
                7D ext.*
              </th>
              <th className="px-4 py-3">Mitjana</th>
              <th className="px-4 py-3">Score</th>
              <th className="px-4 py-3">
                Acció <span className="normal-case text-muted-foreground">(desplega per veure el càlcul)</span>
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
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

      {dailyHistory && dailyHistory.length > 0 && (
        <div className="mt-6 panel p-5">
          <div className="flex items-center justify-between">
            <h2 className="font-semibold">Històric diari</h2>
            <Link to="/history" className="text-xs text-primary hover:underline">
              Veure historial complet →
            </Link>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">Variació del valor de la plantilla (només jugadors) dia a dia.</p>
          <div className="mt-3 divide-y divide-border">
            {[...dailyHistory].reverse().map((row) => (
              <div key={row.day} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span className="capitalize text-muted-foreground">{formatFullDate(new Date(row.day))}</span>
                <div className="flex items-center gap-3">
                  <span className="num font-medium">{formatMoneyM(row.players_value)}</span>
                  <ChangeBadge change={row.team_value_change} changePct={row.team_value_change_pct} />
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
