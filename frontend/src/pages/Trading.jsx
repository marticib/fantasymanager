import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { TrendingUp, Wallet } from 'lucide-react'
import apiClient from '../api/client'
import BidDialog from '../components/BidDialog'
import InfoTip from '../components/InfoTip'
import { PageSkeleton, EmptyState, ErrorState } from '../components/PageState'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { usePersistentState } from '../lib/storage'
import { formatDelta, formatMoney, formatMoneyM, initials, POSITION_LABELS } from '../utils/format'

const HORIZONS = [3, 7, 14]

const MOVE_META = {
  SELL: { label: 'Vendre', text: 'text-bear' },
  BUY: { label: 'Comprar al mercat', text: 'text-bull' },
  CLAUSE: { label: 'Pagar clàusula', text: 'text-info' },
}

function gainClass(value) {
  if (value === null || value === undefined) return 'text-muted-foreground'
  return value > 0 ? 'text-bull' : value < 0 ? 'text-bear' : 'text-muted-foreground'
}

/** A pill-shaped on/off switch with its label. */
function Toggle({ checked, onChange, children }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      onClick={() => onChange(!checked)}
      className={`inline-flex min-h-10 items-center gap-2.5 rounded-full border px-3.5 text-sm font-medium ${
        checked ? 'border-primary/50 bg-primary/10 text-foreground' : 'border-border text-muted-foreground hover:text-foreground'
      }`}
    >
      <span className={`relative h-5 w-9 shrink-0 rounded-full transition-colors ${checked ? 'bg-primary' : 'bg-surface-raised'}`}>
        <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-foreground transition-all ${checked ? 'left-[18px]' : 'left-0.5'}`} />
      </span>
      {children}
    </button>
  )
}

function HorizonCard({ horizon, plan, active, best, onSelect }) {
  const gain = plan?.summary?.netExpectedGain ?? null
  const moves = plan?.movements?.length ?? 0
  return (
    <button
      onClick={onSelect}
      aria-pressed={active}
      className={`panel relative min-w-0 p-3 text-left sm:p-5 ${active ? 'border-primary/60 ring-1 ring-primary/40' : 'hover:border-border-strong'}`}
    >
      {best && <span className="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary shadow-[0_0_8px_var(--color-primary)] sm:hidden" />}
      <div className="flex items-center justify-between gap-2">
        <span className="text-xs font-semibold text-muted-foreground sm:text-sm">{horizon} dies</span>
        {best && <span className="hidden rounded-full bg-primary/15 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-primary sm:inline">Millor</span>}
      </div>
      <p className={`num mt-1.5 text-lg font-bold sm:mt-2 sm:text-3xl ${gain > 0 ? 'text-bull' : 'text-muted-foreground'}`}>
        {gain > 0 ? formatDelta(gain) : '0 €'}
      </p>
      <p className="mt-1 text-[11px] text-muted-foreground sm:text-xs">
        {moves === 0 ? 'No fer res' : `${moves} ${moves === 1 ? 'moviment' : 'moviments'}`}
        <span className="hidden sm:inline">{moves > 0 && ` · inverteixes ${formatMoneyM(plan.summary.capitalToInvest)}`}</span>
      </p>
    </button>
  )
}

function MoveRow({ move, horizon, onBid }) {
  const meta = MOVE_META[move.type]
  const isSell = move.type === 'SELL'
  const later = move.payableInDays != null
  const result = isSell ? move.sellImpact : move.gain
  const amount = isSell ? move.proceeds : move.cost

  return (
    <div className="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-2 px-4 py-3 sm:grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))_auto] sm:px-5">
      <div className="flex min-w-0 items-center gap-3">
        <span className="num w-5 shrink-0 text-right text-xs font-bold text-muted-foreground">{move.step}</span>
        <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-raised text-xs font-bold text-muted-foreground">
          {move.imageUrl ? <img src={move.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(move.name)}
        </div>
        <div className="min-w-0">
          <div className="flex items-center gap-1">
            <Link to={`/players/${move.playerId}`} className="truncate font-semibold hover:text-primary">
              {move.name}
            </Link>
            <InfoTip label="Per què?">{move.explanation}</InfoTip>
          </div>
          <p className="truncate text-xs text-muted-foreground">
            <span className={`font-semibold ${meta.text}`}>
              {meta.label}
              {later && ` d'aquí ${move.payableInDays} ${move.payableInDays === 1 ? 'dia' : 'dies'}`}
            </span>
            {' · '}
            {POSITION_LABELS[move.position] || move.position || '—'}
            {move.type === 'CLAUSE' && move.ownerTeamName ? ` · ${move.ownerTeamName}` : ''}
          </p>
        </div>
      </div>

      <div className="text-right sm:text-left">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{isSell ? 'Cobres' : 'Pagues'}</p>
        <p className="num text-sm font-semibold">{formatMoney(amount)}</p>
      </div>
      <div className="hidden sm:block">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Valor a {horizon}d</p>
        <p className="num text-sm">{formatMoney(move.projectedValue)}</p>
      </div>
      <div className="hidden sm:block">
        <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{isSell ? 'Vs. mantenir' : 'Guany'}</p>
        <p className={`num text-sm font-bold ${gainClass(result)}`}>{formatDelta(result) ?? '—'}</p>
      </div>

      <div className="col-span-2 flex items-center justify-between gap-3 sm:col-span-1 sm:justify-end">
        <p className={`num text-sm font-bold sm:hidden ${gainClass(result)}`}>
          {formatDelta(result) ?? '—'} <span className="text-[11px] font-normal text-muted-foreground">{isSell ? 'vs. mantenir' : `a ${horizon}d`}</span>
        </p>
        {move.type === 'BUY' && move.listingId && (
          <button onClick={() => onBid(move)} className="min-h-9 shrink-0 rounded-lg bg-bull px-3 text-xs font-semibold text-background hover:brightness-110">
            Pujar
          </button>
        )}
        {move.type === 'CLAUSE' && (
          <Link to={`/players/${move.playerId}`} className="flex min-h-9 shrink-0 items-center rounded-lg bg-info/15 px-3 text-xs font-semibold text-info hover:bg-info/25">
            {later ? 'Programar' : 'Comprar'}
          </Link>
        )}
        {isSell && (
          <Link to="/sales" className="flex min-h-9 shrink-0 items-center rounded-lg bg-bear/15 px-3 text-xs font-semibold text-bear hover:bg-bear/25">
            Vendes
          </Link>
        )}
      </div>
    </div>
  )
}

function MoveGroup({ title, total, totalLabel, moves, horizon, onBid }) {
  if (moves.length === 0) return null
  return (
    <section className="panel overflow-hidden">
      <div className="flex items-center justify-between gap-3 border-b border-border bg-surface-raised/40 px-4 py-3 sm:px-5">
        <h3 className="text-sm font-semibold">
          {title} <span className="text-muted-foreground">({moves.length})</span>
        </h3>
        <p className="text-xs text-muted-foreground">
          {totalLabel} <span className="num font-semibold text-foreground">{formatMoney(total)}</span>
        </p>
      </div>
      <div className="divide-y divide-border">
        {moves.map((move) => (
          <MoveRow key={`${move.type}-${move.playerId}`} move={move} horizon={horizon} onBid={onBid} />
        ))}
      </div>
    </section>
  )
}

export default function Trading() {
  const [payload, setPayload] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [respectReserve, setRespectReserve] = usePersistentState('trading-respect-reserve', true)
  const [allowSales, setAllowSales] = usePersistentState('trading-allow-sales', true)
  const [selected, setSelected] = usePersistentState('trading-horizon', null)
  const [pendingBid, setPendingBid] = useState(null)

  const load = () => {
    setLoading(true)
    setError('')
    apiClient
      .get('/trading/plans', { params: { respect_reserve: respectReserve ? 1 : 0, allow_sales: allowSales ? 1 : 0 } })
      .then((res) => setPayload(res.data))
      .catch((err) => setError(err.response?.data?.message || "No s'han pogut calcular els plans."))
      .finally(() => setLoading(false))
  }

  useEffect(load, [respectReserve, allowSales])
  useAppEvent(DATA_CHANGED, load)

  if (error && !payload) return <ErrorState message={error} onRetry={load} />
  if (!payload) return <PageSkeleton cards={3} rows={4} />

  const data = payload.data
  const freshness = payload.meta?.freshness

  if (data.state === 'NO_LEAGUE') {
    return <EmptyState icon={TrendingUp} title="Encara no hi ha cap lliga activa" message={data.message} />
  }

  const horizon = HORIZONS.includes(selected) ? selected : data.bestHorizon ?? 7
  const plan = data.plans[horizon]
  const { capital, summary } = plan

  const openBid = (move) =>
    setPendingBid({
      playerName: move.name,
      marketPlayerId: move.listingId,
      presets: [
        { key: 'value', label: 'Valor', amount: move.marketValue },
        { key: 'recommended', label: 'Recomanada', amount: move.cost },
        { key: 'max', label: 'Màxim', amount: move.maxBid },
      ],
      initialKey: 'recommended',
    })

  return (
    <div className={`transition-opacity ${loading ? 'opacity-70' : ''}`}>
      <BidDialog
        bid={pendingBid}
        onClose={(ok) => {
          setPendingBid(null)
          if (ok) load()
        }}
      />

      <h1 className="text-3xl font-bold tracking-tight">Trading</h1>
      <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
        Com guanyar més diners en 3, 7 o 14 dies amb el capital que tens: compres al mercat, clàusules de rivals (també les que es desbloquegen
        dins del termini) i vendes dels teus jugadors quan surt més a compte.
      </p>

      {freshness?.hasStale && (
        <div className="mt-4 rounded-xl border border-warn/30 bg-warn/10 px-4 py-3 text-xs text-warn">
          {freshness.warnings.map((w) => (
            <p key={w.source}>⚠ {w.message}</p>
          ))}
        </div>
      )}

      <div className="mt-5 flex flex-wrap gap-2">
        <Toggle checked={allowSales} onChange={setAllowSales}>
          Permetre vendre jugadors meus
        </Toggle>
        <Toggle checked={respectReserve} onChange={setRespectReserve}>
          Guardar la reserva mínima{respectReserve && capital.minimumCashReserve ? ` (${formatMoneyM(capital.minimumCashReserve)})` : ''}
        </Toggle>
      </div>

      <div className="mt-4 grid grid-cols-3 gap-2 sm:gap-4">
        {HORIZONS.map((h) => (
          <HorizonCard key={h} horizon={h} plan={data.plans[h]} active={h === horizon} best={h === data.bestHorizon} onSelect={() => setSelected(h)} />
        ))}
      </div>

      <h2 className="mt-6 text-xl font-bold">Pla a {horizon} dies</h2>

      <div className="panel mt-3 grid grid-cols-2 gap-4 p-4 sm:grid-cols-4 sm:p-5">
        <div>
          <p className="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            <Wallet className="h-3.5 w-3.5" /> Caixa per invertir
          </p>
          <p className="num mt-1 text-lg font-bold">{formatMoney(capital.deployableCash)}</p>
        </div>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">De vendes</p>
          {allowSales ? (
            <p className="num mt-1 text-lg font-bold">{formatMoney(summary.capitalFromSales)}</p>
          ) : (
            <p className="mt-1 text-lg font-semibold text-muted-foreground">Desactivat</p>
          )}
        </div>
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Inverteixes</p>
          <p className="num mt-1 text-lg font-bold">{formatMoney(summary.capitalToInvest)}</p>
        </div>
        <div>
          <p className="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            Guany net esperat
            <InfoTip>
              Guany de les compres a {horizon} dies, més el que guanyes o deixes de perdre venent (comparat amb mantenir els jugadors). És una
              projecció de la tendència del valor, no una garantia.
            </InfoTip>
          </p>
          <p className={`num mt-1 text-lg font-bold ${gainClass(summary.netExpectedGain)}`}>
            {summary.netExpectedGain !== null ? formatDelta(summary.netExpectedGain) : '—'}
          </p>
        </div>
      </div>

      {plan.state === 'KEEP_CASH' ? (
        <EmptyState
          icon={Wallet}
          className="mt-4"
          title={`Ara mateix, a ${horizon} dies, el millor és no fer res`}
          message={plan.keepCash?.message || 'Cap compra disponible guanya prou per sobre del mínim configurat.'}
        />
      ) : (
        <div className="mt-4 space-y-4">
          <MoveGroup
            title="1. Vendre"
            totalLabel="Cobres"
            total={summary.capitalFromSales}
            moves={plan.movements.filter((m) => m.type === 'SELL')}
            horizon={horizon}
            onBid={openBid}
          />
          <MoveGroup
            title={plan.movements.some((m) => m.type === 'SELL') ? '2. Comprar' : 'Comprar'}
            totalLabel="Inverteixes"
            total={summary.capitalToInvest}
            moves={plan.movements.filter((m) => m.type !== 'SELL')}
            horizon={horizon}
            onBid={openBid}
          />
          <p className="text-xs text-muted-foreground">
            Després del pla et queden {formatMoney(summary.cashAfter)} de caixa. Només es proposen moviments amb un guany esperat d'almenys{' '}
            {formatMoney(plan.minimumProfitPerMove)} (es canvia a Configuració).
          </p>
        </div>
      )}
    </div>
  )
}
