import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { LineChart, Line, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer, CartesianGrid } from 'recharts'
import apiClient from '../api/client'
import ChangeBadge from '../components/ChangeBadge'
import { ChevronDownIcon, CheckIcon } from '../components/Icons'
import { formatMoney, formatMoneyM, formatDelta, formatPercent, formatFullDate, formatDate, POSITION_LABELS, initials } from '../utils/format'

const RANGE_OPTIONS = [
  { key: '7d', label: '7D' },
  { key: '30d', label: '30D' },
  { key: 'season', label: 'TEMPORADA' },
]

const TYPE_FILTERS = [
  { key: null, label: 'Totes' },
  { key: 'BUY', label: 'Comprar' },
  { key: 'SELL', label: 'Vendre' },
  { key: 'HOLD', label: 'Mantenir' },
  { key: 'CLAUSE', label: 'Clàusula' },
  { key: 'TRADE', label: 'Trade' },
]

const OUTCOME_FILTERS = [
  { key: null, label: 'Totes' },
  { key: 'favorable', label: 'Correctes' },
  { key: 'unfavorable', label: 'Incorrectes' },
  { key: 'PENDING', label: 'Pendents' },
]

const ACTION_LABEL = {
  BUY: 'Comprar',
  SELL: 'Vendre',
  HOLD: 'Mantenir',
  LOCK_CLAUSE: 'Pujar clàusula',
  PAY_CLAUSE: 'Pagar clàusula',
  DO_NOT_CHASE: 'No perseguir',
}

const ACTION_COLOR_CLASS = {
  BUY: 'bg-bull/15 text-bull',
  SELL: 'bg-bear/15 text-bear',
  HOLD: 'bg-muted-foreground/15 text-muted-foreground',
  LOCK_CLAUSE: 'bg-info/15 text-info',
  PAY_CLAUSE: 'bg-info/15 text-info',
  DO_NOT_CHASE: 'bg-muted-foreground/15 text-muted-foreground',
}

const STATUS_LABEL = {
  PENDING: 'Pendent',
  EVALUATED: 'Avaluada',
  INSUFFICIENT_DATA: 'Dades insuficients',
  NOT_EVALUABLE: 'No avaluable',
}

function Pill({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
        active ? 'border-primary bg-primary/15 text-primary' : 'border-border text-muted-foreground hover:border-primary/50'
      }`}
    >
      {children}
    </button>
  )
}

function StatCard({ label, value, hint, accent }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-2 text-2xl font-bold ${accent || ''}`}>{value}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

function DayRow({ row }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="border-b border-border last:border-0">
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-raised"
      >
        <div className="flex items-center gap-3">
          <ChevronDownIcon className={`h-4 w-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
          <div>
            <p className="text-sm font-medium capitalize">{formatFullDate(new Date(row.day))}</p>
            <p className="text-xs text-muted-foreground">{row.player_count} jugadors</p>
          </div>
        </div>
        <div className="text-right">
          <p className="text-sm font-bold">{formatMoneyM(row.total_value ?? row.players_value)}</p>
          <ChangeBadge
            change={row.total_value != null ? row.total_value_change : row.team_value_change}
            changePct={row.total_value != null ? row.total_value_change_pct : row.team_value_change_pct}
          />
        </div>
      </button>
      {open && (
        <div className="border-t border-border bg-background/40 px-4 py-2">
          {row.cash_balance != null && (
            <div className="flex items-center justify-between px-2 py-2 text-sm text-muted-foreground">
              <span>Saldo</span>
              <span className="font-semibold text-foreground">{formatMoneyM(row.cash_balance)}</span>
            </div>
          )}
          {row.players.map((p) => (
            <Link
              key={p.id}
              to={`/players/${p.id}`}
              className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-surface-raised"
            >
              <div className="flex min-w-0 items-center gap-2.5">
                <div className="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
                  {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
                </div>
                <span className="truncate">{p.name}</span>
                <span className="shrink-0 rounded-md border border-border px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                  {POSITION_LABELS[p.position] || p.position || '—'}
                </span>
              </div>
              <div className="shrink-0 text-right">
                <p className="font-medium text-muted-foreground">{formatMoneyM(p.marketValue)}</p>
                <ChangeBadge change={p.change} changePct={p.changePct} />
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

function DecisionRow({ decision }) {
  const [open, setOpen] = useState(false)
  const [detail, setDetail] = useState(null)
  const outcome = decision.outcome

  const toggle = () => {
    setOpen((o) => !o)
    if (!detail) {
      apiClient.get(`/history/decisions/${decision.id}`).then((res) => setDetail(res.data))
    }
  }

  return (
    <div className="border-b border-border last:border-0">
      <button onClick={toggle} className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-raised">
        <ChevronDownIcon className={`h-4 w-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
        <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
          {decision.player?.imageUrl ? (
            <img src={decision.player.imageUrl} alt="" className="h-full w-full object-cover" />
          ) : (
            initials(decision.player?.name)
          )}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={`rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${ACTION_COLOR_CLASS[decision.action] || 'bg-muted-foreground/15 text-muted-foreground'}`}>
              {ACTION_LABEL[decision.action] || decision.action}
            </span>
            <span className="truncate text-sm font-medium">{decision.player?.name || '—'}</span>
          </div>
          <p className="text-xs text-muted-foreground">{formatDate(decision.date)}</p>
        </div>
        <div className="shrink-0 text-right">
          {decision.status === 'EVALUATED' ? (
            <>
              <p className={`text-sm font-bold ${outcome?.favorable ? 'text-bull' : 'text-bear'}`}>
                {formatDelta(outcome?.realProfit ?? outcome?.avoidedLoss ?? outcome?.valueChange ?? 0)}
              </p>
              <p className={`text-xs font-semibold ${outcome?.favorable ? 'text-bull' : 'text-bear'}`}>
                {outcome?.favorable ? 'BONA DECISIÓ' : 'DECISIÓ INCORRECTA'}
              </p>
            </>
          ) : (
            <span className="rounded-md bg-muted-foreground/15 px-2 py-1 text-xs font-semibold text-muted-foreground">{STATUS_LABEL[decision.status]}</span>
          )}
        </div>
      </button>
      {open && (
        <div className="border-t border-border bg-background/40 px-4 py-4">
          {!detail ? (
            <p className="text-sm text-muted-foreground">Carregant…</p>
          ) : (
            <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Valor original</p>
                <p className="font-semibold">{formatMoney(detail.currentMarketValue)}</p>
              </div>
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Preu de referència</p>
                <p className="font-semibold">{formatMoney(detail.referenceValue)}</p>
              </div>
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Score</p>
                <p className="font-semibold">{detail.mainScore ?? '—'}</p>
              </div>
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Confiança</p>
                <p className="font-semibold">{detail.confidence != null ? `${detail.confidence}%` : '—'}</p>
              </div>
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Valor projectat</p>
                <p className="font-semibold">{formatMoney(detail.projectedValue)}</p>
              </div>
              <div>
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Horitzó</p>
                <p className="font-semibold">{detail.horizonDays} dies</p>
              </div>
              {detail.outcome?.actualValue != null && (
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Valor real</p>
                  <p className="font-semibold">{formatMoney(detail.outcome.actualValue)}</p>
                </div>
              )}
              {detail.outcome?.predictionErrorPct != null && (
                <div>
                  <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Error de predicció</p>
                  <p className="font-semibold">{formatPercent(detail.outcome.predictionErrorPct * 100)}</p>
                </div>
              )}
              <div className="col-span-2 sm:col-span-4">
                <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Algorisme</p>
                <p className="font-semibold">{detail.algorithmVersion}</p>
              </div>
              {detail.payload?.reason && (
                <div className="col-span-2 sm:col-span-4">
                  <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Motiu</p>
                  <p className="whitespace-pre-line text-foreground">{detail.payload.reason}</p>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function PerformanceCard({ label, value, hint, icon: Icon }) {
  return (
    <div className="rounded-2xl border border-border bg-surface p-5">
      <div className="flex items-center justify-between">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
        {Icon && <Icon className="h-4 w-4 text-muted-foreground" />}
      </div>
      <p className="mt-2 text-2xl font-bold">{value ?? '—'}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

export default function History() {
  const [range, setRange] = useState('30d')
  const [portfolio, setPortfolio] = useState(null)
  const [performance, setPerformance] = useState(null)
  const [decisions, setDecisions] = useState(null)
  const [typeFilter, setTypeFilter] = useState(null)
  const [outcomeFilter, setOutcomeFilter] = useState(null)
  const [page, setPage] = useState(1)

  useEffect(() => {
    apiClient.get('/history/team-value', { params: { range } }).then((res) => setPortfolio(res.data))
  }, [range])

  useEffect(() => {
    apiClient.get('/history/assistant-performance').then((res) => setPerformance(res.data))
  }, [])

  useEffect(() => {
    const params = { page }
    if (typeFilter === 'CLAUSE') params.action = 'LOCK_CLAUSE,PAY_CLAUSE'
    else if (typeFilter === 'TRADE') params.trade_only = 1
    else if (typeFilter) params.action = typeFilter

    if (outcomeFilter === 'PENDING') params.status = 'PENDING'
    else if (outcomeFilter) params.outcome = outcomeFilter

    apiClient.get('/history/decisions', { params }).then((res) => setDecisions(res.data))
  }, [typeFilter, outcomeFilter, page])

  if (!portfolio) return <p className="text-muted-foreground">Carregant…</p>

  const rows = portfolio.data || []
  const summary = portfolio.summary
  const rowsNewestFirst = [...rows].reverse()
  const chartData = rows.map((r) => ({
    date: formatDate(r.day),
    total: r.total_value,
    players: r.players_value,
    cash: r.cash_balance,
  }))

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Històric</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {rows.length > 0 ? `Temporada actual · ${rows.length} dies registrats` : 'Encara no hi ha dades registrades'}
      </p>

      {rows.length === 0 ? (
        <div className="mt-6 rounded-2xl border border-border bg-surface p-8 text-center">
          <p className="font-semibold">L'històric començarà a construir-se a partir d'aquesta sincronització.</p>
          <p className="mt-1 text-sm text-muted-foreground">
            No inventem una temporada passada — a partir d'ara cada sincronització queda registrada aquí.
          </p>
        </div>
      ) : (
        <>
          <div className="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatCard
              label="Valor inicial"
              value={formatMoneyM(summary.initialValue)}
              hint={!summary.includesCash ? 'Primer registre disponible (sense saldo)' : 'Primer registre disponible'}
            />
            <StatCard label="Valor actual" value={formatMoneyM(summary.currentValue)} />
            <StatCard
              label="Creixement"
              value={formatDelta(summary.growth)}
              accent={summary.growth >= 0 ? 'text-bull' : 'text-bear'}
            />
            <StatCard
              label="ROI"
              value={summary.roiPct != null ? formatPercent(summary.roiPct) : '—'}
              accent={summary.roiPct >= 0 ? 'text-bull' : 'text-bear'}
            />
          </div>

          <div className="mt-6 rounded-2xl border border-border bg-surface p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="font-semibold">Evolució del valor de la plantilla</h2>
              <div className="flex gap-1 rounded-lg border border-border p-0.5">
                {RANGE_OPTIONS.map((r) => (
                  <button
                    key={r.key}
                    onClick={() => setRange(r.key)}
                    className={`rounded-md px-2.5 py-1 text-xs font-semibold ${
                      range === r.key ? 'bg-primary text-background' : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {r.label}
                  </button>
                ))}
              </div>
            </div>
            <div className="mt-4 h-72">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                  <XAxis dataKey="date" stroke="var(--color-text-muted)" fontSize={11} />
                  <YAxis stroke="var(--color-text-muted)" fontSize={11} tickFormatter={(v) => `${(v / 1_000_000).toFixed(1)}M`} />
                  <Tooltip
                    contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)' }}
                    formatter={(v) => formatMoney(v)}
                  />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Line type="monotone" name="Valor total" dataKey="total" stroke="var(--color-bull)" strokeWidth={2} dot={false} connectNulls />
                  <Line type="monotone" name="Valor jugadors" dataKey="players" stroke="var(--color-primary)" strokeWidth={2} dot={false} />
                  <Line type="monotone" name="Saldo" dataKey="cash" stroke="var(--color-info)" strokeWidth={2} dot={false} connectNulls />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>

          <div className="mt-4 overflow-hidden rounded-2xl border border-border bg-surface">
            <div className="border-b border-border px-4 py-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Valor per dia · desplega per veure el desglossament per jugador
              </p>
            </div>
            {rowsNewestFirst.map((row) => (
              <DayRow key={row.day} row={row} />
            ))}
          </div>
        </>
      )}

      <div className="mt-10">
        <h2 className="text-xl font-bold">Rendiment de l'assistent</h2>
        <p className="mt-1 text-sm text-muted-foreground">Backtest real de les recomanacions econòmiques, mai una estadística inventada.</p>

        {!performance || performance.evaluatedCount === 0 ? (
          <div className="mt-4 rounded-2xl border border-border bg-surface p-6 text-sm text-muted-foreground">
            Encara no hi ha prou recomanacions avaluades.
            {performance?.pendingCount > 0 && (
              <> {performance.pendingCount} {performance.pendingCount === 1 ? 'recomanació pendent' : 'recomanacions pendents'} d'avaluació.</>
            )}
          </div>
        ) : (
          <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <PerformanceCard
              label="Precisió"
              value={performance.accuracyPct != null ? formatPercent(performance.accuracyPct, { showSign: false }) : '—'}
              hint={`Sobre ${performance.evaluatedCount} recomanacions avaluades`}
              icon={CheckIcon}
            />
            <PerformanceCard
              label="Benefici teòric"
              value={performance.theoreticalProfit != null ? formatDelta(performance.theoreticalProfit) : '—'}
            />
            <PerformanceCard
              label="ROI mitjà"
              value={performance.averageRoiPct != null ? formatPercent(performance.averageRoiPct) : '—'}
            />
          </div>
        )}
      </div>

      <div className="mt-8">
        <h2 className="text-xl font-bold">Historial de decisions</h2>

        <div className="mt-3 flex flex-wrap gap-4">
          <div className="flex flex-wrap gap-2">
            {TYPE_FILTERS.map((f) => (
              <Pill key={f.label} active={typeFilter === f.key} onClick={() => { setTypeFilter(f.key); setPage(1) }}>
                {f.label}
              </Pill>
            ))}
          </div>
          <div className="flex flex-wrap gap-2">
            {OUTCOME_FILTERS.map((f) => (
              <Pill key={f.label} active={outcomeFilter === f.key} onClick={() => { setOutcomeFilter(f.key); setPage(1) }}>
                {f.label}
              </Pill>
            ))}
          </div>
        </div>

        <div className="mt-4 overflow-hidden rounded-2xl border border-border bg-surface">
          {!decisions ? (
            <p className="p-6 text-center text-sm text-muted-foreground">Carregant…</p>
          ) : decisions.data.length === 0 ? (
            <p className="p-6 text-center text-sm text-muted-foreground">Cap decisió amb aquests filtres.</p>
          ) : (
            decisions.data.map((d) => <DecisionRow key={d.id} decision={d} />)
          )}
        </div>

        {decisions && decisions.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
            <button
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-40"
            >
              Anterior
            </button>
            <span>
              Pàgina {decisions.current_page} de {decisions.last_page}
            </span>
            <button
              disabled={page >= decisions.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-lg border border-border px-3 py-1.5 disabled:opacity-40"
            >
              Següent
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
