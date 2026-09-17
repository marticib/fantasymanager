import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import TodayActionCard from '../components/TodayActionCard'
import { WarningIcon, RefreshIcon, CheckIcon } from '../components/Icons'
import { formatFullDate, formatMoneyM, greeting, initials } from '../utils/format'

const STALE_AFTER_MINUTES = 90

function PlannedRow({ action }) {
  return (
    <Link
      to={action.player ? `/players/${action.player.id}` : '#'}
      className="flex items-center gap-3 rounded-xl border border-border bg-surface p-4 hover:border-info/50"
    >
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-info/15 text-xs font-bold text-info">
        {initials(action.player?.name)}
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-info">{action.title}</p>
        <p className="truncate text-xs text-muted-foreground">{action.player?.name}</p>
      </div>
      {action.mainScore != null && (
        <span className="shrink-0 rounded-md bg-info/10 px-2 py-1 text-xs font-bold text-info">{Math.round(action.mainScore)}</span>
      )}
    </Link>
  )
}

function WatchRow({ action }) {
  return (
    <Link
      to={action.player ? `/players/${action.player.id}` : '#'}
      className="flex items-center gap-3 rounded-xl border border-border bg-surface p-4 hover:border-primary/50"
    >
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted-foreground/15 text-xs font-bold text-muted-foreground">
        {initials(action.player?.name)}
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold">{action.player?.name}</p>
        <p className="truncate text-xs text-muted-foreground">{action.shortExplanation}</p>
      </div>
      {action.mainScore != null && (
        <span className="shrink-0 rounded-md bg-muted-foreground/10 px-2 py-1 text-xs font-bold text-muted-foreground">{Math.round(action.mainScore)}</span>
      )}
    </Link>
  )
}

export default function Dashboard() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [syncing, setSyncing] = useState(false)
  const [ordersNeedingConfirmation, setOrdersNeedingConfirmation] = useState([])

  const load = () => {
    apiClient
      .get('/dashboard/today')
      .then((res) => setData(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant el dashboard.'))

    apiClient
      .get('/clause-orders', { params: { status: 'NEEDS_CONFIRMATION' } })
      .then((res) => setOrdersNeedingConfirmation(res.data.data))
      .catch(() => {})
  }

  useEffect(load, [])

  const runSync = async () => {
    setSyncing(true)
    try {
      await apiClient.post('/fantasy/sync')
    } finally {
      setTimeout(() => {
        setSyncing(false)
        load()
      }, 1500)
    }
  }

  if (error) return <p className="text-bear">{error}</p>
  if (!data) return <p className="text-muted-foreground">Carregant…</p>

  if (!data.hasTeamSelected) {
    return (
      <div className="rounded-2xl border border-border bg-surface p-8 text-center">
        <p className="text-lg font-semibold">Encara no tens cap equip seleccionat</p>
        <p className="mt-2 text-sm text-muted-foreground">Completa la configuració inicial per veure les teves recomanacions.</p>
        <Link to="/onboarding" className="mt-4 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-bg">
          Anar a la configuració
        </Link>
      </div>
    )
  }

  const isStale = data.lastSyncedAt && (Date.now() - new Date(data.lastSyncedAt).getTime()) / 60000 > STALE_AFTER_MINUTES
  const today = data.today
  const actions = today?.actions || { urgent: [], opportunity: [], planned: [], watch: [] }

  return (
    <div>
      <p className="text-sm text-muted-foreground">{greeting()} 👋</p>
      <h1 className="mt-1 text-3xl font-bold tracking-tight">Avui</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {formatFullDate()}
        {data.currentMatchday ? ` · Jornada ${data.currentMatchday}` : ''}
        {today && !today.nothingToDoToday ? ` · ${today.summary}` : ''}
      </p>

      {ordersNeedingConfirmation.length > 0 && (
        <div className="mt-5 rounded-xl border border-info/30 bg-info/10 px-5 py-4">
          <div className="flex items-start gap-3">
            <WarningIcon className="mt-0.5 h-5 w-5 shrink-0 text-info" />
            <p className="text-sm font-semibold text-info">
              {ordersNeedingConfirmation.length === 1
                ? '1 ordre de compra automàtica espera confirmació'
                : `${ordersNeedingConfirmation.length} ordres de compra automàtica esperen confirmació`}{' '}
              — la clàusula ha pujat des que es va programar.
            </p>
          </div>
          <div className="mt-3 space-y-1.5">
            {ordersNeedingConfirmation.map((order) => (
              <Link
                key={order.id}
                to={`/players/${order.player.id}`}
                className="flex items-center justify-between rounded-lg bg-surface px-3 py-2 text-sm hover:bg-surface-raised"
              >
                <span className="font-semibold">{order.player.name}</span>
                <span className="text-muted-foreground">{formatMoneyM(order.pendingConfirmationClauseValue)}</span>
              </Link>
            ))}
          </div>
        </div>
      )}

      {isStale && (
        <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warn/30 bg-warn/10 px-5 py-4">
          <div className="flex items-start gap-3">
            <WarningIcon className="mt-0.5 h-5 w-5 shrink-0 text-warn" />
            <div>
              <p className="text-sm font-semibold text-warn">Dades desactualitzades</p>
              <p className="text-xs text-muted-foreground">
                Última sincronització fa una estona llarga. Els valors poden haver canviat.
              </p>
            </div>
          </div>
          <button
            onClick={runSync}
            disabled={syncing}
            className="flex items-center gap-1.5 rounded-lg bg-warn/20 px-3 py-1.5 text-sm font-semibold text-warn hover:bg-warn/30 disabled:opacity-50"
          >
            <RefreshIcon className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            {syncing ? 'Sincronitzant…' : 'Sincronitzar ara'}
          </button>
        </div>
      )}

      {!today || today.nothingToDoToday ? (
        <div className="mt-8 flex flex-col items-center rounded-2xl border border-border bg-surface p-10 text-center">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-bull/15">
            <CheckIcon className="h-6 w-6 text-bull" />
          </div>
          <p className="mt-4 text-lg font-semibold">Avui no cal fer res</p>
          <p className="mt-1 text-sm text-muted-foreground">No hi ha cap acció econòmica prioritària.</p>
        </div>
      ) : (
        <>
          {actions.urgent.length > 0 && (
            <div className="mt-8">
              <h2 className="text-xl font-bold">Prioritat màxima</h2>
              <p className="text-sm text-muted-foreground">Accions que realment s'han de fer avui.</p>
              <div className="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3">
                {actions.urgent.map((action, i) => (
                  <TodayActionCard key={`${action.type}-${action.player?.id}-${i}`} action={action} />
                ))}
              </div>
            </div>
          )}

          {actions.opportunity.length > 0 && (
            <div className="mt-8">
              <h2 className="text-xl font-bold">Oportunitats</h2>
              <p className="text-sm text-muted-foreground">Operacions interessants però no necessàriament urgents.</p>
              <div className="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3">
                {actions.opportunity.map((action, i) => (
                  <TodayActionCard key={`${action.type}-${action.player?.id}-${i}`} action={action} />
                ))}
              </div>
            </div>
          )}

          {actions.planned.length > 0 && (
            <div className="mt-10">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Planificat ({actions.planned.length})
              </h2>
              <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {actions.planned.map((action, i) => (
                  <PlannedRow key={`${action.type}-${action.player?.id}-${i}`} action={action} />
                ))}
              </div>
            </div>
          )}

          {actions.watch.length > 0 && (
            <div className="mt-10">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                Seguiment ({actions.watch.length})
              </h2>
              <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {actions.watch.map((action, i) => (
                  <WatchRow key={`${action.type}-${action.player?.id}-${i}`} action={action} />
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}
