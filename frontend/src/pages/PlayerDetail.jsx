import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { LineChart, Line, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import apiClient from '../api/client'
import ScoreRing from '../components/ScoreRing'
import { ArrowRightIcon, CheckIcon, WarningIcon, SignalIcon, ChevronDownIcon } from '../components/Icons'
import {
  formatMoney,
  formatMoneyM,
  formatDelta,
  formatPercent,
  formatDate,
  POSITION_LABELS,
  PLAYER_TYPE_LABEL,
  classifyPlayerType,
  initials,
} from '../utils/format'

const RANGE_OPTIONS = [
  { key: '7D', days: 7 },
  { key: '30D', days: 30 },
  { key: 'TEMPORADA', days: null },
]

// Per decision.type — deliberately local: the roster vocabulary (LOCK_CLAUSE
// = "pujar clàusula") differs from the legacy fantasy_recommendations one
// this page used to read, and the buy/clause vocabularies aren't shared
// with any single existing map either.
const DECISION_LABEL = {
  HOLD: 'MANTENIR',
  SELL: 'VENDRE',
  LOCK_CLAUSE: 'PUJAR CLÀUSULA',
  BUY: 'COMPRAR',
  CONSIDER: 'CONSIDERAR',
  WAIT: 'ESPERAR',
  DO_NOT_BUY: 'NO COMPRAR',
  DO_NOT_CHASE: 'NO PERSEGUIR',
  PAY_CLAUSE: 'PAGAR CLÀUSULA',
  DO_NOT_PAY: 'NO PAGAR',
}
const DECISION_COLOR = {
  HOLD: 'hold',
  SELL: 'sell',
  LOCK_CLAUSE: 'clause',
  BUY: 'buy',
  CONSIDER: 'accent',
  WAIT: 'trading',
  DO_NOT_BUY: 'sell',
  DO_NOT_CHASE: 'hold',
  PAY_CLAUSE: 'clause',
  DO_NOT_PAY: 'sell',
}

const ACTION_TEXT_CLASS = { buy: 'text-buy', sell: 'text-sell', hold: 'text-hold', clause: 'text-clause', trading: 'text-trading', accent: 'text-accent' }
const ACTION_BG_CLASS = {
  buy: 'bg-buy/10 text-buy',
  sell: 'bg-sell/10 text-sell',
  hold: 'bg-hold/10 text-hold',
  clause: 'bg-clause/10 text-clause',
  trading: 'bg-trading/10 text-trading',
  accent: 'bg-accent/10 text-accent',
}

const ACCENT_BORDER_CLASS = {
  buy: 'border-l-buy',
  sell: 'border-l-sell',
  clause: 'border-l-clause',
  trading: 'border-l-trading',
}

const RAW_FIELD_LABEL = {
  currentMarketValue: 'Valor actual',
  acquisitionPrice: 'Preu d’adquisició',
  projectedValue14d: 'Valor projectat 14d',
  expectedProfit14d: 'Benefici esperat 14d',
  expectedROI14d: 'ROI 14d',
  breakEvenDays: 'Break-even (dies)',
  maxBid: 'MaxBid',
  recommendedBid: 'RecommendedBid',
  marketValue: 'Valor de mercat',
  clauseValue: 'Clàusula',
  clausePremiumPct: 'Prima de clàusula',
  roi14d: 'ROI 14d',
  clauseEconomicScore: 'Clause Economic Score',
  appreciation7d: 'Revalorització 7d',
  tradeScore: 'Trade Score',
  sellScore: 'Sell Score',
  adjustedSellScore: 'Adjusted Sell Score',
  holdScore: 'Hold Score',
  clauseScore: 'Clause Score',
}

function StatTile({ label, value, accent }) {
  return (
    <div className={`rounded-2xl border border-border bg-surface p-4 border-l-2 ${ACCENT_BORDER_CLASS[accent] || 'border-l-border'}`}>
      <p className="text-xs uppercase tracking-wide text-text-muted">{label}</p>
      <div className="mt-1 text-xl font-bold">{value}</div>
    </div>
  )
}

function formatRawValue(key, value) {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (/pct|roi/i.test(key) && typeof value === 'number') return formatPercent(value * 100)
  if (/value|price|profit|bid|amount/i.test(key) && typeof value === 'number' && Math.abs(value) > 1000) return formatMoneyM(value)
  if (typeof value === 'number') return Number.isInteger(value) ? value : value.toFixed(2)
  return String(value)
}

function CalculationDisclosure({ raw }) {
  const [open, setOpen] = useState(false)
  if (!raw) return null
  const entries = Object.entries(raw).filter(([, v]) => typeof v !== 'object' || v === null)

  return (
    <div className="mt-4 border-t border-border pt-3">
      <button onClick={() => setOpen((o) => !o)} className="flex items-center gap-1.5 text-xs font-semibold text-text-muted hover:text-text">
        <ChevronDownIcon className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
        Veure càlcul
      </button>
      {open && (
        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
          {entries.map(([key, value]) => (
            <div key={key} className="flex items-center justify-between gap-2 border-b border-border/50 pb-1">
              <dt className="text-text-muted">{RAW_FIELD_LABEL[key] || key}</dt>
              <dd className="font-semibold">{formatRawValue(key, value)}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  )
}

export default function PlayerDetail() {
  const { id } = useParams()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [range, setRange] = useState('30D')
  const [orderBusy, setOrderBusy] = useState(false)
  const [orderError, setOrderError] = useState('')

  const loadPlayer = () => {
    apiClient
      .get(`/players/${id}`)
      .then((res) => setData(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant el jugador.'))
  }

  useEffect(loadPlayer, [id])

  const runOrderAction = async (action) => {
    setOrderBusy(true)
    setOrderError('')
    try {
      await action()
      await loadPlayer()
    } catch (err) {
      setOrderError(err.response?.data?.message || 'Error processant l’ordre.')
    } finally {
      setOrderBusy(false)
    }
  }

  const createClauseOrder = () => runOrderAction(() => apiClient.post('/clause-orders', { fantasy_player_id: data.player.id }))
  const confirmClauseOrder = (orderId) => runOrderAction(() => apiClient.post(`/clause-orders/${orderId}/confirm`))
  const cancelClauseOrder = (orderId) => runOrderAction(() => apiClient.delete(`/clause-orders/${orderId}`))

  const chartData = useMemo(() => {
    if (!data) return []
    const days = RANGE_OPTIONS.find((r) => r.key === range)?.days
    const points = days ? data.history.slice(-days) : data.history
    const real = points.map((h) => ({ date: formatDate(h.capturedAt), value: h.marketValue }))
    const proj = data.projections
    if (!proj || real.length === 0) return real

    const last = real[real.length - 1]
    const projected = [
      { date: last.date, value: last.value, projected: last.value },
      proj.value3d != null && { date: '+3d', projected: proj.value3d },
      proj.value7d != null && { date: '+7d', projected: proj.value7d },
      proj.value14d != null && { date: '+14d', projected: proj.value14d },
    ].filter(Boolean)

    return [...real.slice(0, -1), ...projected]
  }, [data, range])

  if (error) return <p className="text-sell">{error}</p>
  if (!data) return <p className="text-text-muted">Carregant…</p>

  const decision = data.decision
  const color = decision ? DECISION_COLOR[decision.action] || 'hold' : 'hold'
  const label = decision ? DECISION_LABEL[decision.action] || decision.action : null
  const type = classifyPlayerType(data.trend.classification, data.player.averagePoints)
  // Own snapshot history is sparse right after connecting an account — fall
  // back to futbolfantasy's daily trend (already fetched for the "Tendència
  // externa" block below) rather than showing "—", same *ext. convention
  // used for sparklines elsewhere (Market/Team/Clauses).
  const change24h = data.trend.change24h
  const change24hExternal = change24h == null ? data.externalTrend?.pct1d ?? null : null
  const pct7d = data.trend.pctChange7d
  const pct7dExternal = pct7d == null ? data.externalTrend?.pct7d ?? null : null
  const raw = decision?.raw

  return (
    <div>
      <Link to="/players" className="inline-flex items-center gap-1.5 text-sm text-text-muted hover:text-text">
        ← Jugadors
      </Link>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border bg-surface p-6">
        <div className="flex items-center gap-4">
          <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-hold/15 text-lg font-bold text-hold">
            {data.player.imageUrl ? (
              <img src={data.player.imageUrl} alt="" className="h-full w-full object-cover" />
            ) : (
              initials(data.player.name)
            )}
          </div>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">{data.player.name}</h1>
            <div className="mt-1 flex items-center gap-2 text-sm text-text-muted">
              <span>{data.player.club}</span>
              <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
                {POSITION_LABELS[data.player.position] || data.player.position}
              </span>
              <span className="rounded-md border border-buy/40 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-buy">
                {PLAYER_TYPE_LABEL[type]}
              </span>
            </div>
          </div>
        </div>
        <ScoreRing score={data.fantasyScore.total} />
      </div>

      <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-5">
        <StatTile label="Valor" value={formatMoneyM(data.player.marketValue)} accent="buy" />
        <StatTile
          label={change24h == null && change24hExternal != null ? '24h *ext.' : '24h'}
          value={
            change24h != null ? (
              <span className={`inline-flex items-center gap-1 rounded-md bg-current/10 px-1.5 py-0.5 text-sm ${change24h >= 0 ? 'text-buy' : 'text-sell'}`}>
                {change24h >= 0 ? '↗' : '↘'} {formatDelta(change24h)}
              </span>
            ) : change24hExternal != null ? (
              <span
                className={`inline-flex items-center gap-1 rounded-md bg-current/10 px-1.5 py-0.5 text-sm ${change24hExternal >= 0 ? 'text-buy' : 'text-sell'}`}
                title="Font externa (futbolfantasy.com, no oficial) — encara no tenim prou historial propi"
              >
                {change24hExternal >= 0 ? '↗' : '↘'} {formatPercent(change24hExternal)}
              </span>
            ) : (
              '—'
            )
          }
        />
        <StatTile
          label={pct7d == null && pct7dExternal != null ? '7 dies *ext.' : '7 dies'}
          value={
            pct7d != null ? (
              <span className={`inline-flex items-center gap-1 rounded-md bg-current/10 px-1.5 py-0.5 text-sm ${pct7d >= 0 ? 'text-buy' : 'text-sell'}`}>
                {pct7d >= 0 ? '↗' : '↘'} {formatPercent(pct7d)}
              </span>
            ) : pct7dExternal != null ? (
              <span
                className={`inline-flex items-center gap-1 rounded-md bg-current/10 px-1.5 py-0.5 text-sm ${pct7dExternal >= 0 ? 'text-buy' : 'text-sell'}`}
                title="Font externa (futbolfantasy.com, no oficial) — encara no tenim prou historial propi"
              >
                {pct7dExternal >= 0 ? '↗' : '↘'} {formatPercent(pct7dExternal)}
              </span>
            ) : (
              '—'
            )
          }
        />
        <StatTile label="Punts" value={data.player.points} />
        <StatTile label="Mitjana" value={data.player.averagePoints?.toFixed(1)} accent="clause" />
      </div>

      <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-[1fr_360px]">
        <div className="space-y-4">
          <div className="rounded-2xl border border-border bg-surface p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-semibold">Evolució del valor</h2>
              <div className="flex gap-1 rounded-lg border border-border p-0.5">
                {RANGE_OPTIONS.map((r) => (
                  <button
                    key={r.key}
                    onClick={() => setRange(r.key)}
                    className={`rounded-md px-2.5 py-1 text-xs font-semibold ${
                      range === r.key ? 'bg-accent text-bg' : 'text-text-muted hover:text-text'
                    }`}
                  >
                    {r.key}
                  </button>
                ))}
              </div>
            </div>
            {data.historySource === 'external' && (
              <p className="mt-1 text-xs text-text-muted" title="futbolfantasy.com — no és una font oficial de LaLiga">
                font: futbolfantasy.com (no oficial)
              </p>
            )}
            <div className="mt-4 h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                  <XAxis dataKey="date" stroke="var(--color-text-muted)" fontSize={11} />
                  <YAxis
                    stroke="var(--color-text-muted)"
                    fontSize={11}
                    domain={['auto', 'auto']}
                    tickFormatter={(v) => `${(v / 1_000_000).toFixed(1)}M`}
                  />
                  <Tooltip
                    contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)' }}
                    formatter={(v) => formatMoney(v)}
                  />
                  <Line type="monotone" dataKey="value" stroke="var(--color-buy)" strokeWidth={2} dot={false} />
                  {data.projections && (
                    <Line
                      type="monotone"
                      dataKey="projected"
                      stroke="var(--color-accent)"
                      strokeWidth={2}
                      strokeDasharray="5 5"
                      dot={false}
                    />
                  )}
                </LineChart>
              </ResponsiveContainer>
            </div>
            {data.projections && <p className="mt-2 text-xs text-text-muted">Línia discontínua: projecció econòmica (3d/7d/14d), no historial real.</p>}
          </div>

          {data.weekPoints.length > 0 && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h2 className="font-semibold">Punts per jornada</h2>
              <div className="mt-4 h-56">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.weekPoints.map((w) => ({ week: `J${w.weekNumber}`, points: w.points }))}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                    <XAxis dataKey="week" stroke="var(--color-text-muted)" fontSize={11} />
                    <YAxis stroke="var(--color-text-muted)" fontSize={11} allowDecimals={false} />
                    <Tooltip contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)' }} />
                    <Bar dataKey="points" fill="var(--color-accent)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </div>
          )}

          {data.externalTrend && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-text-muted">Tendència externa</h2>
                <span className="text-xs text-text-muted" title="futbolfantasy.com — no és una font oficial de LaLiga">
                  font: futbolfantasy.com (no oficial)
                </span>
              </div>
              <div className="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-5">
                {[
                  ['1d', data.externalTrend.pct1d],
                  ['3d', data.externalTrend.pct3d],
                  ['7d', data.externalTrend.pct7d],
                  ['14d', data.externalTrend.pct14d],
                  ['30d', data.externalTrend.pct30d],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-xl border border-border p-3 text-center">
                    <p className="text-[11px] uppercase tracking-wide text-text-muted">{label}</p>
                    <p className={`mt-1 font-bold ${value > 0 ? 'text-buy' : value < 0 ? 'text-sell' : 'text-text-muted'}`}>
                      {value != null ? formatPercent(value) : '—'}
                    </p>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        <div className="space-y-4">
          <div className="rounded-2xl border border-border bg-surface p-5">
            <p className="text-xs font-semibold uppercase tracking-wide text-text-muted">Què faria jo?</p>
            {decision ? (
              <>
                <p className={`mt-2 text-3xl font-extrabold tracking-tight ${ACTION_TEXT_CLASS[color]}`}>{label}</p>
                {data.context === 'FREE' && (
                  <p className="mt-1 text-xs text-text-muted">
                    Hipotètic — el jugador no és al mercat ara mateix, comparat amb el seu valor actual.
                  </p>
                )}
                {decision.confidence != null && (
                  <p className="mt-2 flex items-center gap-1.5 text-sm text-text-muted">
                    <SignalIcon className="h-3.5 w-3.5" />
                    {decision.confidence}% confiança
                    {decision.confidence < 50 && <span className="text-trading">· predicció amb historial limitat</span>}
                  </p>
                )}

                {decision.type === 'BUY' && (
                  <div className="mt-4 grid grid-cols-2 gap-2">
                    {typeof raw.recommendedBid === 'number' && (
                      <div className="rounded-xl bg-buy/10 p-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-buy/80">Oferta recomanada</p>
                        <p className="mt-1 text-lg font-bold text-buy">{formatMoneyM(raw.recommendedBid)}</p>
                      </div>
                    )}
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">No superar (MaxBid)</p>
                      <p className="mt-1 text-lg font-bold">{formatMoneyM(raw.maxBid)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">ROI 14d</p>
                      <p className="mt-1 text-lg font-bold">{formatPercent(raw.expectedROI14d * 100)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Break-even</p>
                      <p className="mt-1 text-lg font-bold">{raw.breakEvenDays != null ? `${raw.breakEvenDays} dies` : '—'}</p>
                    </div>
                  </div>
                )}

                {decision.type === 'CLAUSE' && (
                  <div className="mt-4 grid grid-cols-2 gap-2">
                    <div className="rounded-xl bg-clause/10 p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-clause/80">Clàusula</p>
                      <p className="mt-1 text-lg font-bold text-clause">{formatMoneyM(raw.clauseValue)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Prima</p>
                      <p className="mt-1 text-lg font-bold">{formatPercent(raw.clausePremiumPct * 100)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">ROI 14d</p>
                      <p className="mt-1 text-lg font-bold">{formatPercent(raw.roi14d * 100)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Break-even</p>
                      <p className="mt-1 text-lg font-bold">{raw.breakEvenDays != null ? `${raw.breakEvenDays} dies` : '—'}</p>
                    </div>
                  </div>
                )}

                {decision.type === 'ROSTER' && decision.action === 'LOCK_CLAUSE' && raw.clauseTiming && (
                  <div className="mt-4 grid grid-cols-2 gap-2">
                    <div className="rounded-xl bg-clause/10 p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-clause/80">Cost</p>
                      <p className="mt-1 text-lg font-bold text-clause">{formatMoneyM(data.clauseValue)}</p>
                    </div>
                    <div className="rounded-xl border border-border p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Moment</p>
                      <p className="mt-1 text-lg font-bold">{raw.clauseTiming.recommendedExecution === 'NOW' ? 'Avui' : 'Últim dia segur'}</p>
                    </div>
                    {raw.clauseTiming.profitableTarget != null && (
                      <div className="rounded-xl border border-border p-3" title="No superar el valor projectat a 14 dies — recomanació pròpia, no un límit confirmat de LaLiga.">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-buy/80">Rendible fins a</p>
                        <p className="mt-1 text-lg font-bold text-buy">{formatMoneyM(raw.clauseTiming.profitableTarget)}</p>
                        <p className="mt-0.5 text-[10px] text-text-muted">
                          {raw.clauseTiming.profitableTargetCost > 0
                            ? `et costaria ${formatMoneyM(raw.clauseTiming.profitableTargetCost)}`
                            : 'ja hi ets'}
                        </p>
                      </div>
                    )}
                    {raw.clauseTiming.antiTheftTarget != null && (
                      <div className="rounded-xl border border-border p-3" title="Prima a partir de la qual el risc de robatori és pràcticament nul — recomanació pròpia, no un límit confirmat de LaLiga.">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-clause/80">Anti-robatori des de</p>
                        <p className="mt-1 text-lg font-bold text-clause">{formatMoneyM(raw.clauseTiming.antiTheftTarget)}</p>
                        <p className="mt-0.5 text-[10px] text-text-muted">
                          {raw.clauseTiming.antiTheftTargetCost > 0
                            ? `et costaria ${formatMoneyM(raw.clauseTiming.antiTheftTargetCost)}`
                            : 'ja hi ets'}
                        </p>
                      </div>
                    )}
                  </div>
                )}

                {decision.type === 'ROSTER' && decision.action === 'SELL' && (
                  <div className="mt-4 grid grid-cols-2 gap-2">
                    <div className="rounded-xl bg-sell/10 p-3">
                      <p className="text-[11px] font-semibold uppercase tracking-wide text-sell/80">Motiu</p>
                      <p className="mt-1 text-sm font-bold text-sell">{raw.sellReasonCode || '—'}</p>
                    </div>
                    {raw.trade?.currentOffer != null && (
                      <div className="rounded-xl border border-border p-3">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-text-muted">Oferta actual</p>
                        <p className="mt-1 text-lg font-bold">{formatMoneyM(raw.trade.currentOffer)}</p>
                      </div>
                    )}
                  </div>
                )}

                {(decision.favors.length > 0 || decision.risks.length > 0) && (
                  <div className="mt-4 grid grid-cols-2 gap-3">
                    {decision.favors.length > 0 && (
                      <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-buy">A favor</p>
                        <ul className="mt-1.5 space-y-1.5 text-sm">
                          {decision.favors.map((f, i) => (
                            <li key={i} className="flex items-start gap-1.5">
                              <CheckIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-buy" />
                              {f}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                    {decision.risks.length > 0 && (
                      <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-trading">Riscos</p>
                        <ul className="mt-1.5 space-y-1.5 text-sm text-text-muted">
                          {decision.risks.map((r, i) => (
                            <li key={i} className="flex items-start gap-1.5">
                              <WarningIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-trading" />
                              {r}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </div>
                )}

                {decision.reason && <p className="mt-4 whitespace-pre-line text-sm text-text-muted">{decision.reason}</p>}

                <CalculationDisclosure raw={raw} />
              </>
            ) : (
              <p className="mt-3 text-sm text-text-muted">Sense prou dades per a una recomanació ara mateix.</p>
            )}
          </div>

          <div className="rounded-2xl border border-border bg-surface p-5">
            <h2 className="font-semibold">Informació de la lliga</h2>
            <div className="mt-3 grid grid-cols-2 gap-4">
              <div>
                <p className="text-xs uppercase tracking-wide text-text-muted">Propietari</p>
                <p className="mt-1 font-semibold">{data.owner ? (data.owner.isMine ? 'Tu' : data.owner.name) : 'Lliure'}</p>
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-text-muted">Valor</p>
                <p className="mt-1 font-semibold">{formatMoneyM(data.player.marketValue)}</p>
              </div>
              {data.context !== 'ON_MARKET' && data.clauseValue != null && (
                <>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-text-muted">Clàusula</p>
                    <p className="mt-1 font-semibold text-clause">{formatMoneyM(data.clauseValue)}</p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-text-muted">Prima clàusula</p>
                    <p className="mt-1 font-semibold text-clause">
                      {data.clausePremiumPct != null ? formatPercent(data.clausePremiumPct * 100) : '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-wide text-text-muted">Protecció</p>
                    <p className="mt-1 font-semibold">{data.isClauseLocked ? 'Blindada' : 'Desbloquejada'}</p>
                  </div>
                  {data.isClauseLocked && (
                    <div>
                      <p className="text-xs uppercase tracking-wide text-text-muted">Desbloqueig</p>
                      <p className="mt-1 font-semibold">{formatDate(data.clauseLockedUntil)}</p>
                    </div>
                  )}
                </>
              )}
              {data.context === 'ON_MARKET' && data.listing?.expiresAt && (
                <div>
                  <p className="text-xs uppercase tracking-wide text-text-muted">Mercat tanca</p>
                  <p className="mt-1 font-semibold">{formatDate(data.listing.expiresAt)}</p>
                </div>
              )}
            </div>
            {decision && (
              <span className={`mt-4 inline-flex rounded-lg px-3 py-2 text-sm font-semibold ${ACTION_BG_CLASS[color]}`}>{label}</span>
            )}

            {data.owner && !data.owner.isMine && data.clauseValue != null && (
              <div className="mt-4 border-t border-border pt-4">
                {!data.clausePurchaseOrder && (
                  <button
                    onClick={createClauseOrder}
                    disabled={orderBusy}
                    className="w-full rounded-lg bg-clause/15 px-3 py-2 text-sm font-semibold text-clause hover:bg-clause/25 disabled:opacity-50"
                  >
                    🤖 Programar compra automàtica (clàusula)
                  </button>
                )}

                {data.clausePurchaseOrder?.status === 'PENDING' && (
                  <div className="flex items-center justify-between gap-3 rounded-lg border border-clause/30 bg-clause/10 px-3 py-2">
                    <p className="text-sm text-clause">🤖 Ordre activa — es comprarà en desbloquejar-se</p>
                    <button
                      onClick={() => cancelClauseOrder(data.clausePurchaseOrder.id)}
                      disabled={orderBusy}
                      className="shrink-0 text-xs font-semibold text-text-muted hover:text-sell disabled:opacity-50"
                    >
                      Cancel·lar
                    </button>
                  </div>
                )}

                {data.clausePurchaseOrder?.status === 'NEEDS_CONFIRMATION' && (
                  <div className="rounded-lg border border-trading/30 bg-trading/10 px-3 py-3">
                    <p className="text-sm font-semibold text-trading">
                      La clàusula ha pujat a {formatMoneyM(data.clausePurchaseOrder.pendingConfirmationClauseValue)} (abans{' '}
                      {formatMoneyM(data.clausePurchaseOrder.clauseValueAtOrder)})
                    </p>
                    <p className="mt-1 text-xs text-text-muted">Cal confirmar per comprar-la al preu nou.</p>
                    <div className="mt-2 flex gap-2">
                      <button
                        onClick={() => confirmClauseOrder(data.clausePurchaseOrder.id)}
                        disabled={orderBusy}
                        className="rounded-lg bg-buy/20 px-3 py-1.5 text-xs font-semibold text-buy hover:bg-buy/30 disabled:opacity-50"
                      >
                        Confirmar compra
                      </button>
                      <button
                        onClick={() => cancelClauseOrder(data.clausePurchaseOrder.id)}
                        disabled={orderBusy}
                        className="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-muted hover:text-sell disabled:opacity-50"
                      >
                        Cancel·lar
                      </button>
                    </div>
                  </div>
                )}

                {data.clausePurchaseOrder?.status === 'EXECUTED' && (
                  <p className="rounded-lg border border-buy/30 bg-buy/10 px-3 py-2 text-sm font-semibold text-buy">
                    ✅ Comprat automàticament per {formatMoneyM(data.clausePurchaseOrder.executedClauseValue)}
                  </p>
                )}

                {data.clausePurchaseOrder?.status === 'FAILED' && (
                  <div className="rounded-lg border border-sell/30 bg-sell/10 px-3 py-2">
                    <p className="text-sm font-semibold text-sell">L’ordre ha fallat</p>
                    {data.clausePurchaseOrder.errorMessage && (
                      <p className="mt-1 text-xs text-text-muted">{data.clausePurchaseOrder.errorMessage}</p>
                    )}
                    <button
                      onClick={createClauseOrder}
                      disabled={orderBusy}
                      className="mt-2 text-xs font-semibold text-clause hover:text-clause/80 disabled:opacity-50"
                    >
                      Torna-ho a provar
                    </button>
                  </div>
                )}

                {orderError && <p className="mt-2 text-xs text-sell">{orderError}</p>}
              </div>
            )}
          </div>

          {data.alternatives.length > 0 && (
            <div className="rounded-2xl border border-border bg-surface p-5">
              <h2 className="font-semibold">Alternatives similars</h2>
              <p className="text-xs text-text-muted">Mateixa posició, millor economia de compra</p>
              <div className="mt-3 divide-y divide-border">
                {data.alternatives.map((alt) => (
                  <Link
                    key={alt.id}
                    to={`/players/${alt.id}`}
                    className="flex items-center justify-between gap-2 py-2.5 text-sm hover:text-accent"
                  >
                    <span className="truncate font-medium">{alt.name}</span>
                    <span className="flex shrink-0 items-center gap-2 text-text-muted">
                      {formatMoneyM(alt.marketValue)} · {alt.buyEconomicScore} · ROI {formatPercent(alt.roi14d * 100)}
                      <ArrowRightIcon className="h-3.5 w-3.5" />
                    </span>
                  </Link>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
