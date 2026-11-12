import { useEffect, useMemo, useState } from 'react'
import { Link, useOutletContext } from 'react-router-dom'
import apiClient from '../api/client'
import { cachedGet } from '../api/cachedGet'
import TodayActionCard from '../components/TodayActionCard'
import BidDialog from '../components/BidDialog'
import { PageSkeleton, ErrorState } from '../components/PageState'
import { WarningIcon, RefreshIcon, CheckIcon } from '../components/Icons'
import { DATA_CHANGED, REQUEST_SYNC, emitAppEvent, useAppEvent } from '../lib/appEvents'
import { readStored, writeStored } from '../lib/storage'
import { PREV_VISIT_KEY, alertCountLabel } from '../lib/alerts'
import { formatDelta, formatFullDate, formatMoneyM, formatRelativeTime, greeting, initials } from '../utils/format'

const STALE_AFTER_MINUTES = 90
const DISMISSED_KEY = 'fantasy:today-dismissed'
const SINCE_SEEN_KEY = 'fantasy:since-card-seen'

const todayKey = () => new Date().toISOString().slice(0, 10)
const actionKey = (action) => `${action.type}-${action.player?.id ?? action.title}`

/** Cards marked "Fet"/"Ignorar" stay hidden for the rest of the day only. */
function useDismissedToday() {
  const [state, setState] = useState(() => {
    const stored = readStored(window.localStorage, DISMISSED_KEY, null)
    return stored?.date === todayKey() ? stored : { date: todayKey(), keys: {} }
  })

  const dismiss = (key, how) => {
    setState((prev) => {
      const next = { date: todayKey(), keys: { ...(prev.date === todayKey() ? prev.keys : {}), [key]: how } }
      writeStored(window.localStorage, DISMISSED_KEY, next)
      return next
    })
  }

  const restoreAll = () => {
    const next = { date: todayKey(), keys: {} }
    writeStored(window.localStorage, DISMISSED_KEY, next)
    setState(next)
  }

  return [state.date === todayKey() ? state.keys : {}, dismiss, restoreAll]
}

function PlannedRow({ action }) {
  return (
    <Link
      to={action.player ? `/players/${action.player.id}` : '#'}
      className="flex items-center gap-3 panel p-4 hover:border-info/50"
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
      className="flex items-center gap-3 panel p-4 hover:border-primary/50"
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

/** "Des de la teva última visita": what changed while the user was away. */
function SinceLastVisit() {
  const [since] = useState(() => readStored(window.sessionStorage, PREV_VISIT_KEY, null))
  const [hidden, setHidden] = useState(() => readStored(window.sessionStorage, SINCE_SEEN_KEY, false))
  const [data, setData] = useState(null)

  useEffect(() => {
    if (!since || hidden) return
    apiClient
      .get('/dashboard/since', { params: { since } })
      .then((res) => setData(res.data))
      .catch(() => {})
  }, [since, hidden])

  if (!since || hidden || !data) return null

  const counts = Object.entries(data.alertCounts || {})
  const delta = data.teamValueDelta
  if (counts.length === 0 && !delta) return null

  const close = () => {
    writeStored(window.sessionStorage, SINCE_SEEN_KEY, true)
    setHidden(true)
  }

  return (
    <div className="mt-5 rounded-xl border border-primary/30 bg-primary/5 px-5 py-4">
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm font-semibold">Des de la teva última visita ({formatRelativeTime(since)})</p>
        <button onClick={close} className="min-h-9 shrink-0 rounded-md px-2 text-xs font-semibold text-muted-foreground hover:text-foreground">
          Entesos
        </button>
      </div>
      <ul className="mt-2 space-y-1 text-sm text-muted-foreground">
        {delta ? (
          <li>
            El valor del teu equip ha {delta > 0 ? 'pujat' : 'baixat'}{' '}
            <span className={`num font-semibold ${delta > 0 ? 'text-bull' : 'text-bear'}`}>{formatDelta(delta)}</span>.
          </li>
        ) : null}
        {counts.map(([type, count]) => (
          <li key={type}>{alertCountLabel(type, count)}.</li>
        ))}
      </ul>
      {data.latest?.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {data.latest.map((a, i) =>
            a.link ? (
              <Link key={i} to={a.link} className="rounded-lg bg-surface px-3 py-1.5 text-xs font-semibold hover:text-primary">
                {a.title} →
              </Link>
            ) : (
              <span key={i} className="rounded-lg bg-surface px-3 py-1.5 text-xs">
                {a.title}
              </span>
            ),
          )}
        </div>
      )}
    </div>
  )
}

export default function Dashboard() {
  const { header } = useOutletContext() || {}
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [ordersNeedingConfirmation, setOrdersNeedingConfirmation] = useState([])
  const [pendingBid, setPendingBid] = useState(null)
  const [showHidden, setShowHidden] = useState(false)
  const [dismissed, dismiss, restoreAll] = useDismissedToday()

  const load = () => {
    setError('')
    cachedGet('/dashboard/today', null, setData).catch((err) =>
      setError(err.response?.data?.message || "No s'ha pogut carregar la pàgina d'avui."),
    )

    cachedGet('/clause-orders', { status: 'NEEDS_CONFIRMATION' }, (d) => setOrdersNeedingConfirmation(d.data)).catch(() => {})
  }

  useEffect(load, [])
  useAppEvent(DATA_CHANGED, load)

  const askBid = (action, initialKey) => {
    if (!action.marketPlayerId) return
    const recommended = typeof action.recommendedBid === 'number' ? action.recommendedBid : null
    setPendingBid({
      playerName: action.player?.name,
      marketPlayerId: action.marketPlayerId,
      presets: [
        { key: 'value', label: 'Valor', amount: action.currentValue },
        { key: 'recommended', label: 'Recomanada', amount: recommended },
        { key: 'max', label: 'Màxim', amount: action.maxBid },
      ],
      initialKey,
    })
  }

  const handleBidClose = (wasSuccess) => {
    setPendingBid(null)
    if (wasSuccess) load()
  }

  const actions = useMemo(() => data?.today?.actions || { urgent: [], opportunity: [], planned: [], watch: [] }, [data])
  const visible = (list) => (showHidden ? list : list.filter((a) => !dismissed[actionKey(a)]))
  const hiddenCount = [...actions.urgent, ...actions.opportunity].filter((a) => dismissed[actionKey(a)]).length

  if (error && !data) return <ErrorState message={error} onRetry={load} />
  if (!data) return <PageSkeleton cards={3} rows={4} />

  if (!data.hasTeamSelected) {
    return (
      <div className="panel p-8 text-center">
        <p className="text-lg font-semibold">Encara no tens cap equip seleccionat</p>
        <p className="mt-2 text-sm text-muted-foreground">Completa la configuració inicial per veure les teves recomanacions.</p>
        <Link to="/onboarding" className="mt-4 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-background">
          Anar a la configuració
        </Link>
      </div>
    )
  }

  const lastSyncedAt = header?.lastSyncedAt ?? data.lastSyncedAt
  const isStale = lastSyncedAt && (Date.now() - new Date(lastSyncedAt).getTime()) / 60000 > STALE_AFTER_MINUTES
  const syncing = Boolean(header?.syncing)
  const today = data.today
  const urgent = visible(actions.urgent)
  const opportunity = visible(actions.opportunity)
  const allDone = urgent.length === 0 && opportunity.length === 0 && hiddenCount > 0

  const renderCard = (action, i) => {
    const key = actionKey(action)
    return (
      <div key={`${key}-${i}`} className={dismissed[key] ? 'opacity-50' : ''}>
        <TodayActionCard action={action} onBid={askBid} onDismiss={dismissed[key] ? undefined : (how) => dismiss(key, how)} />
      </div>
    )
  }

  return (
    <div>
      <BidDialog bid={pendingBid} onClose={handleBidClose} />

      <p className="text-sm text-muted-foreground">{greeting()} 👋</p>
      <h1 className="mt-1 text-3xl font-bold tracking-tight">Avui</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {formatFullDate()}
        {data.currentMatchday ? ` · Jornada ${data.currentMatchday}` : ''}
        {today && !today.nothingToDoToday ? ` · ${today.summary}` : ''}
      </p>

      <SinceLastVisit />

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
                className="flex min-h-11 items-center justify-between rounded-lg bg-surface px-3 py-2 text-sm hover:bg-surface-raised"
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
                L'última sincronització va ser {formatRelativeTime(lastSyncedAt)}. Els valors poden haver canviat.
              </p>
            </div>
          </div>
          <button
            onClick={() => emitAppEvent(REQUEST_SYNC)}
            disabled={syncing}
            className="flex min-h-10 items-center gap-1.5 rounded-lg bg-warn/20 px-3 py-1.5 text-sm font-semibold text-warn hover:bg-warn/30 disabled:opacity-50"
          >
            <RefreshIcon className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            {syncing ? 'Sincronitzant…' : 'Sincronitzar ara'}
          </button>
        </div>
      )}

      {!today || today.nothingToDoToday || allDone ? (
        <div className="mt-8 flex flex-col items-center panel p-10 text-center">
          <div className="flex h-12 w-12 items-center justify-center rounded-full bg-bull/15">
            <CheckIcon className="h-6 w-6 text-bull" />
          </div>
          <p className="mt-4 text-lg font-semibold">{allDone ? 'Ja ho tens tot fet per avui' : 'Avui no cal fer res'}</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {allDone ? 'Has marcat totes les accions com a fetes o ignorades.' : 'No hi ha cap acció econòmica prioritària.'}
          </p>
        </div>
      ) : (
        <>
          {urgent.length > 0 && (
            <div className="mt-8">
              <h2 className="text-xl font-bold">Prioritat màxima</h2>
              <p className="text-sm text-muted-foreground">Accions que realment s'han de fer avui.</p>
              <div className="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3">{urgent.map(renderCard)}</div>
            </div>
          )}

          {opportunity.length > 0 && (
            <div className="mt-8">
              <h2 className="text-xl font-bold">Oportunitats</h2>
              <p className="text-sm text-muted-foreground">Operacions interessants però no necessàriament urgents.</p>
              <div className="mt-4 grid grid-cols-1 gap-5 lg:grid-cols-3">{opportunity.map(renderCard)}</div>
            </div>
          )}
        </>
      )}

      {hiddenCount > 0 && (
        <div className="mt-4 flex flex-wrap gap-3 text-sm">
          <button onClick={() => setShowHidden((v) => !v)} className="min-h-10 font-semibold text-primary hover:underline">
            {showHidden ? 'Amaga les accions fetes o ignorades' : `Mostra les accions amagades (${hiddenCount})`}
          </button>
          <button onClick={restoreAll} className="min-h-10 text-muted-foreground hover:text-foreground">
            Restaurar-les totes
          </button>
        </div>
      )}

      {actions.planned.length > 0 && (
        <div className="mt-10">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Planificat ({actions.planned.length})</h2>
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {actions.planned.map((action, i) => (
              <PlannedRow key={`${action.type}-${action.player?.id}-${i}`} action={action} />
            ))}
          </div>
        </div>
      )}

      {actions.watch.length > 0 && (
        <div className="mt-10">
          <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Seguiment ({actions.watch.length})</h2>
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {actions.watch.map((action, i) => (
              <WatchRow key={`${action.type}-${action.player?.id}-${i}`} action={action} />
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
