import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { ShieldIcon, WarningIcon, CheckIcon } from './Icons'
import { formatMoneyM, formatCountdown, formatDate, initials, POSITION_LABELS } from '../utils/format'

const STATUS_LABELS = {
  PENDING: 'Programada',
  NEEDS_CONFIRMATION: 'Espera confirmació',
  EXECUTED: 'Executada',
  FAILED: 'Fallida',
  CANCELLED: 'Cancel·lada',
}

const STATUS_BADGE = {
  PENDING: 'bg-info/15 text-info',
  NEEDS_CONFIRMATION: 'bg-warn/15 text-warn',
  EXECUTED: 'bg-bull/15 text-bull',
  FAILED: 'bg-bear/15 text-bear',
  CANCELLED: 'bg-muted-foreground/15 text-muted-foreground',
}

const ACTIVE_STATUSES = ['PENDING', 'NEEDS_CONFIRMATION']

function OrderIdentity({ order }) {
  return (
    <Link to={`/players/${order.player.id}`} className="flex min-w-0 items-center gap-3 hover:opacity-80">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
        {order.player.imageUrl ? <img src={order.player.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(order.player.name)}
      </div>
      <div className="min-w-0">
        <p className="truncate font-semibold">{order.player.name}</p>
        <p className="truncate text-xs text-muted-foreground">
          {order.player.club || '—'} · {POSITION_LABELS[order.player.position] || order.player.position || '—'} · {order.targetTeam?.name || 'rival'}
        </p>
      </div>
    </Link>
  )
}

function LockStatus({ order }) {
  if (!order.stillOnTargetTeam) {
    return <span className="text-xs text-warn">Ja no és a aquest equip — no es podrà pagar</span>
  }
  if (order.isLocked) {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
        <ShieldIcon className="h-3.5 w-3.5" />
        Blindada, es desbloqueja {formatCountdown(order.clauseLockedUntil) ? `d'aquí ${formatCountdown(order.clauseLockedUntil)}` : `el ${formatDate(order.clauseLockedUntil)}`}
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1 text-xs text-bull">
      <CheckIcon className="h-3.5 w-3.5" />
      Desbloquejada — es paga en el proper cicle
    </span>
  )
}

function OrderRow({ order, onConfirm, onCancel, busy }) {
  const priceRisen = order.status === 'NEEDS_CONFIRMATION'

  return (
    <div className="panel p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <OrderIdentity order={order} />
        <span className={`shrink-0 rounded-md px-2 py-1 text-xs font-bold ${STATUS_BADGE[order.status]}`}>{STATUS_LABELS[order.status]}</span>
      </div>

      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
        <div>
          <p className="num text-lg font-bold">
            {formatMoneyM(priceRisen ? order.pendingConfirmationClauseValue : order.clauseValueAtOrder)}
          </p>
          {priceRisen && (
            <p className="num text-xs text-muted-foreground">abans {formatMoneyM(order.clauseValueAtOrder)}</p>
          )}
        </div>
        {order.status === 'PENDING' && <LockStatus order={order} />}
        {order.status === 'EXECUTED' && (
          <span className="text-xs text-muted-foreground">
            Pagada per {formatMoneyM(order.executedClauseValue)} el {formatDate(order.executedAt)}
          </span>
        )}
        {order.status === 'FAILED' && order.errorMessage && <span className="text-xs text-bear">{order.errorMessage}</span>}
      </div>

      {priceRisen && (
        <div className="mt-3 flex items-center gap-2 rounded-lg border border-warn/30 bg-warn/10 px-3 py-2">
          <WarningIcon className="h-4 w-4 shrink-0 text-warn" />
          <p className="flex-1 text-xs text-warn">La clàusula ha pujat des que es va programar. Accepta el preu nou per mantenir l'ordre activa.</p>
        </div>
      )}

      {ACTIVE_STATUSES.includes(order.status) && (
        <div className="mt-3 flex gap-2">
          {priceRisen && (
            <button
              onClick={() => onConfirm(order.id)}
              disabled={busy}
              className="rounded-lg bg-bull/20 px-3 py-1.5 text-xs font-semibold text-bull hover:bg-bull/30 disabled:opacity-50"
            >
              Acceptar nou preu
            </button>
          )}
          <button
            onClick={() => onCancel(order.id)}
            disabled={busy}
            className="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-muted-foreground hover:text-bear disabled:opacity-50"
          >
            Cancel·lar
          </button>
        </div>
      )}
    </div>
  )
}

/**
 * Every automated clause-purchase order this account has placed, active or
 * historical, with confirm/cancel management — the counterpart to the
 * per-player creation flow on PlayerDetail.jsx. Mounted on /clauses since
 * that's this feature's natural home.
 */
export default function ClauseOrdersPanel() {
  const [orders, setOrders] = useState(null)
  const [error, setError] = useState('')
  const [busyId, setBusyId] = useState(null)
  const [showHistory, setShowHistory] = useState(false)

  const load = () => {
    apiClient
      .get('/clause-orders')
      .then((res) => setOrders(res.data.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant les ordres de compra automàtica.'))
  }

  useEffect(load, [])

  const runAction = async (id, action) => {
    setBusyId(id)
    try {
      await action()
      load()
    } catch (err) {
      setError(err.response?.data?.message || 'No s\'ha pogut completar l\'acció.')
    } finally {
      setBusyId(null)
    }
  }

  const confirmOrder = (id) => runAction(id, () => apiClient.post(`/clause-orders/${id}/confirm`))
  const cancelOrder = (id) => runAction(id, () => apiClient.delete(`/clause-orders/${id}`))

  if (error && !orders) return <p className="text-bear">{error}</p>
  if (!orders) return null
  if (orders.length === 0) return null

  const active = orders.filter((o) => ACTIVE_STATUSES.includes(o.status))
  const history = orders.filter((o) => !ACTIVE_STATUSES.includes(o.status))
  const needsConfirmation = active.filter((o) => o.status === 'NEEDS_CONFIRMATION').length

  return (
    <div className="mt-5">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold tracking-tight">🤖 Ordres de compra automàtica</h2>
        {needsConfirmation > 0 && (
          <span className="rounded-full bg-warn/15 px-2.5 py-1 text-xs font-bold text-warn">
            {needsConfirmation} {needsConfirmation === 1 ? 'espera confirmació' : 'esperen confirmació'}
          </span>
        )}
      </div>

      {error && <p className="mt-2 text-xs text-bear">{error}</p>}

      {active.length > 0 ? (
        <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
          {active.map((order) => (
            <OrderRow key={order.id} order={order} onConfirm={confirmOrder} onCancel={cancelOrder} busy={busyId === order.id} />
          ))}
        </div>
      ) : (
        <p className="mt-2 text-sm text-muted-foreground">Cap ordre activa ara mateix.</p>
      )}

      {history.length > 0 && (
        <details className="mt-4" open={showHistory} onToggle={(e) => setShowHistory(e.target.open)}>
          <summary className="cursor-pointer text-sm font-medium text-muted-foreground">
            Historial ({history.length})
          </summary>
          <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            {history.map((order) => (
              <OrderRow key={order.id} order={order} onConfirm={confirmOrder} onCancel={cancelOrder} busy={busyId === order.id} />
            ))}
          </div>
        </details>
      )}
    </div>
  )
}
