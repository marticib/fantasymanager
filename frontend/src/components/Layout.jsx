import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import apiClient from '../api/client'
import { Suspense, useCallback, useEffect, useRef, useState } from 'react'
import { formatRelativeTime, initials } from '../utils/format'
import { LogoIcon } from './Icons'
import { PageSkeleton } from './PageState'
import { ACTION_DONE, DATA_CHANGED, REQUEST_SYNC, SESSION_EXPIRED, emitAppEvent, useAppEvent } from '../lib/appEvents'
import { readStored, writeStored } from '../lib/storage'
import { LAST_VISIT_KEY, PREV_VISIT_KEY, SEVERITY_DOT } from '../lib/alerts'
import {
  Zap,
  Users,
  ShoppingBag,
  Tag,
  Shield,
  TrendingUp,
  Target,
  Trophy,
  BarChart3,
  Settings as SettingsIcon,
  RefreshCw,
  Calendar,
  ChevronDown,
  Menu,
  Clock,
  Bell,
  AlertTriangle,
  X,
} from 'lucide-react'

const NAV_ITEMS = [
  { to: '/', label: 'Avui', end: true, icon: Zap },
  { to: '/market', label: 'Mercat', icon: ShoppingBag, badge: 'market', badgeHelp: 'ofertes teves actives' },
  // No badge: the whole squad is usually listed with LaLiga's standing offer, so a count would always show.
  { to: '/sales', label: 'Vendes', icon: Tag },
  { to: '/team', label: 'El meu equip', short: 'Equip', icon: Users },
  { to: '/clauses', label: 'Clàusules', icon: Shield, badge: 'clauses', badgeHelp: 'compres automàtiques per confirmar' },
  { to: '/trading', label: 'Trading', icon: TrendingUp },
  { to: '/players', label: 'Jugadors', icon: Target },
  { to: '/standings', label: 'Classificació', icon: Trophy },
  { to: '/history', label: 'Històric', icon: BarChart3 },
]
const SETTINGS_ITEM = { to: '/settings', label: 'Configuració', icon: SettingsIcon }

// The daily flow: what to do today → buy → sell → my squad. Everything else is behind "Més".
const MOBILE_TAB_PATHS = ['/', '/market', '/sales', '/team']

const HEADER_POLL_MS = 60_000
const SYNC_POLL_MS = 3_000
const NOTIFIED_KEY = 'fantasy:notified-alert-id'

/** Closes a popover on outside click, Escape and navigation. */
function usePopover() {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const location = useLocation()

  useEffect(() => {
    if (!open) return undefined
    const onDown = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false)
    const onKey = (e) => e.key === 'Escape' && setOpen(false)
    document.addEventListener('pointerdown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  useEffect(() => setOpen(false), [location.pathname])

  return { open, setOpen, ref }
}

/**
 * The first time a browser session opens the app, remember when the previous
 * visit was so Avui can show "since your last visit". Runs during Layout's
 * first render (before the pages read it), and is idempotent.
 */
function startVisitTracking() {
  try {
    if (!window.sessionStorage.getItem('fantasy_session_started')) {
      window.sessionStorage.setItem('fantasy_session_started', '1')
      const previous = readStored(window.localStorage, LAST_VISIT_KEY, null)
      if (previous) writeStored(window.sessionStorage, PREV_VISIT_KEY, previous)
    }
  } catch {
    // storage blocked — the summary just won't appear
  }
  writeStored(window.localStorage, LAST_VISIT_KEY, new Date().toISOString())
}

function CountBadge({ count, help, className = '' }) {
  if (!count) return null
  return (
    <span
      className={`num inline-flex min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold leading-5 text-background ${className}`}
      aria-label={help ? `${count} ${help}` : String(count)}
    >
      {count > 99 ? '99+' : count}
    </span>
  )
}

function HeaderClock() {
  const [now, setNow] = useState(() => new Date())

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(id)
  }, [])

  return (
    <span className="hidden shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground lg:flex">
      <Clock className="h-4 w-4" />
      <span className="num font-semibold text-foreground">
        {new Intl.DateTimeFormat('ca-ES', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(now)}
      </span>
    </span>
  )
}

function LeagueSwitcher({ leagueName }) {
  const { open, setOpen, ref } = usePopover()
  const [leagues, setLeagues] = useState(null)
  const [activeLeagueId, setActiveLeagueId] = useState(null)
  const [switching, setSwitching] = useState(null)

  const toggle = async () => {
    setOpen((o) => !o)
    if (!leagues) {
      try {
        const { data } = await apiClient.get('/leagues')
        setLeagues(data.data)
        setActiveLeagueId(data.activeLeagueId ?? null)
      } catch {
        setLeagues([])
      }
    }
  }

  const select = async (league) => {
    setSwitching(league.id)
    try {
      await apiClient.post(`/leagues/${league.id}/select`)
      window.location.reload()
    } finally {
      setSwitching(null)
    }
  }

  return (
    // flex + min-w-0 on the wrapper is what lets the name truncate instead of overflowing onto the buttons next to it.
    <div className="relative flex min-w-0" ref={ref}>
      <button
        onClick={toggle}
        aria-expanded={open}
        className="flex min-h-10 min-w-0 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-sm font-medium hover:border-primary/50"
      >
        <Trophy className="h-4 w-4 shrink-0 text-muted-foreground" />
        <span className="max-w-24 truncate sm:max-w-40">{leagueName || 'Sense lliga'}</span>
        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      </button>
      {open && (
        <div className="panel elevated absolute left-0 top-full z-30 mt-2 w-64 p-2">
          {!leagues && <p className="px-2 py-2 text-xs text-muted-foreground">Carregant lligues…</p>}
          {leagues?.map((league) => (
            <button
              key={league.id}
              onClick={() => select(league)}
              disabled={switching === league.id}
              className={`flex min-h-11 w-full items-center justify-between rounded-lg px-3 text-left text-sm hover:bg-surface-raised ${
                league.id === activeLeagueId ? 'text-primary' : ''
              }`}
            >
              <span className="truncate">{league.name}</span>
              {league.id === activeLeagueId && <span className="text-xs">✓</span>}
            </button>
          ))}
          {leagues?.length === 0 && <p className="px-2 py-2 text-xs text-muted-foreground">Cap lliga detectada.</p>}
        </div>
      )}
    </div>
  )
}

function SyncButton({ syncing, lastSyncedAt, onSync }) {
  return (
    <div className="flex shrink-0 items-center gap-2">
      <button
        onClick={onSync}
        disabled={syncing}
        aria-label={syncing ? 'Sincronitzant' : 'Sincronitzar ara'}
        className="flex min-h-10 min-w-10 shrink-0 items-center justify-center gap-1.5 rounded-lg border border-border px-2.5 text-sm font-semibold hover:border-primary/50 disabled:opacity-60 sm:px-3"
      >
        <RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin text-primary' : ''}`} />
        <span className="hidden sm:inline">{syncing ? 'Sincronitzant…' : 'Sincronitzar'}</span>
      </button>
      <span className="hidden whitespace-nowrap text-xs text-muted-foreground xl:inline">
        {syncing ? 'Actualitzant dades de LaLiga…' : lastSyncedAt ? `Actualitzat ${formatRelativeTime(lastSyncedAt)}` : 'Encara no sincronitzat'}
      </span>
    </div>
  )
}

function NotificationBell({ unread, onChanged }) {
  const { open, setOpen, ref } = usePopover()
  const [alerts, setAlerts] = useState(null)
  const navigate = useNavigate()
  const [permission, setPermission] = useState(() => (typeof Notification === 'undefined' ? 'unsupported' : Notification.permission))

  const load = () =>
    apiClient
      .get('/alerts')
      .then((res) => setAlerts(res.data.data))
      .catch(() => setAlerts([]))

  const toggle = () => {
    if (!open) load()
    setOpen((o) => !o)
  }

  const openAlert = async (alert) => {
    setOpen(false)
    if (!alert.read) {
      apiClient
        .post(`/alerts/${alert.id}/read`)
        .then(onChanged)
        .catch(() => {})
    }
    if (alert.link) navigate(alert.link)
  }

  const readAll = async () => {
    await apiClient.post('/alerts/read-all').catch(() => {})
    setAlerts((list) => list?.map((a) => ({ ...a, read: true })))
    onChanged()
  }

  const askPermission = async () => {
    try {
      setPermission(await Notification.requestPermission())
    } catch {
      // ignore
    }
  }

  return (
    <div className="relative shrink-0" ref={ref}>
      <button
        onClick={toggle}
        aria-label={unread ? `Avisos, ${unread} sense llegir` : 'Avisos'}
        aria-expanded={open}
        className="relative flex min-h-10 min-w-10 items-center justify-center rounded-lg border border-border hover:border-primary/50"
      >
        <Bell className="h-4.5 w-4.5" />
        <CountBadge count={unread} className="absolute -right-1.5 -top-1.5" />
      </button>
      {open && (
        <div className="panel elevated fixed inset-x-3 top-16 z-40 flex max-h-[70vh] flex-col sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-96">
          <div className="flex items-center justify-between border-b border-border px-4 py-3">
            <p className="text-sm font-semibold">Avisos</p>
            {alerts?.some((a) => !a.read) && (
              <button onClick={readAll} className="text-xs font-semibold text-primary hover:underline">
                Marcar-ho tot com a llegit
              </button>
            )}
          </div>
          <div className="flex-1 overflow-y-auto">
            {!alerts && <p className="px-4 py-6 text-center text-sm text-muted-foreground">Carregant…</p>}
            {alerts?.length === 0 && (
              <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                Cap avís de moment. Aquí t'avisarem d'ofertes noves, subhastes acabades i compres automàtiques.
              </p>
            )}
            {alerts?.map((alert) => (
              <button
                key={alert.id}
                onClick={() => openAlert(alert)}
                className={`flex w-full gap-3 border-b border-border px-4 py-3 text-left last:border-0 hover:bg-surface-raised ${alert.read ? 'opacity-60' : ''}`}
              >
                <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${alert.read ? 'bg-transparent' : SEVERITY_DOT[alert.severity] || 'bg-info'}`} />
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-semibold">{alert.title}</span>
                  <span className="mt-0.5 block text-xs text-muted-foreground">{alert.message}</span>
                  <span className="mt-1 block text-[11px] text-muted-foreground">{formatRelativeTime(alert.createdAt)}</span>
                </span>
              </button>
            ))}
          </div>
          {permission === 'default' && (
            <div className="border-t border-border px-4 py-3">
              <button onClick={askPermission} className="min-h-10 w-full rounded-lg bg-primary/15 text-sm font-semibold text-primary hover:bg-primary/25">
                Rebre aquests avisos també al navegador
              </button>
            </div>
          )}
          {permission === 'denied' && (
            <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
              Els avisos del navegador estan bloquejats. Pots activar-los als permisos del lloc web.
            </p>
          )}
        </div>
      )}
    </div>
  )
}

function UserMenu({ user, onLogout }) {
  const { open, setOpen, ref } = usePopover()

  return (
    <div className="relative shrink-0" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        aria-label="Menú d'usuari"
        aria-expanded={open}
        className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary hover:bg-primary/25"
      >
        {initials(user?.name)}
      </button>
      {open && (
        <div className="panel elevated absolute right-0 top-full z-30 mt-2 w-56 p-1">
          <p className="truncate px-3 py-2 text-xs text-muted-foreground">{user?.email}</p>
          <Link to="/settings" className="flex min-h-10 items-center gap-2 rounded-md px-3 text-sm hover:bg-surface-raised">
            <SettingsIcon className="h-4 w-4" /> Configuració
          </Link>
          <button onClick={onLogout} className="min-h-10 w-full rounded-md px-3 text-left text-sm font-semibold text-bear hover:bg-bear/10">
            Sortir
          </button>
        </div>
      )}
    </div>
  )
}

function Sidebar({ badges }) {
  const linkClass = ({ isActive }) =>
    `flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors ${
      isActive ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-surface-raised hover:text-foreground'
    }`

  return (
    <aside className="sticky top-0 hidden h-screen w-56 shrink-0 flex-col border-r border-border bg-surface md:flex">
      <Link to="/" className="flex items-center gap-2.5 px-5 py-4">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary">
          <LogoIcon className="h-5 w-5" />
        </span>
        <span className="text-sm font-bold leading-tight tracking-tight">Fantasy Assistant</span>
      </Link>
      <nav className="flex-1 space-y-0.5 overflow-y-auto px-3 py-2" aria-label="Navegació principal">
        {NAV_ITEMS.map((item) => (
          <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
            <item.icon className="h-4.5 w-4.5 shrink-0" />
            <span className="flex-1">{item.label}</span>
            <CountBadge count={item.badge && badges?.[item.badge]} help={item.badgeHelp} />
          </NavLink>
        ))}
      </nav>
      <div className="border-t border-border px-3 py-3">
        <NavLink to={SETTINGS_ITEM.to} className={linkClass}>
          <SettingsIcon className="h-4.5 w-4.5 shrink-0" />
          {SETTINGS_ITEM.label}
        </NavLink>
      </div>
    </aside>
  )
}

function MobileBottomNav({ badges }) {
  const { open, setOpen, ref } = usePopover()
  const location = useLocation()
  const tabs = MOBILE_TAB_PATHS.map((path) => NAV_ITEMS.find((i) => i.to === path))
  const moreItems = [...NAV_ITEMS.filter((i) => !MOBILE_TAB_PATHS.includes(i.to)), SETTINGS_ITEM]
  const moreBadge = moreItems.reduce((sum, i) => sum + ((i.badge && badges?.[i.badge]) || 0), 0)
  const moreActive = moreItems.some((i) => location.pathname.startsWith(i.to))

  const tabClass = ({ isActive }) =>
    `relative flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-xs font-medium ${isActive ? 'text-primary' : 'text-muted-foreground'}`

  const moreLinkClass = ({ isActive }) =>
    `flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm font-medium ${
      isActive ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-surface-raised hover:text-foreground'
    }`

  return (
    // Square-cornered on purpose (not `panel`): it's an edge-to-edge fixed bar.
    <nav
      className="fixed inset-x-0 bottom-0 z-40 flex border-t border-border bg-surface pb-[env(safe-area-inset-bottom)] elevated md:hidden"
      aria-label="Navegació principal"
    >
      {tabs.map((item) => (
        <NavLink key={item.to} to={item.to} end={item.end} className={tabClass}>
          <span className="relative">
            <item.icon className="h-5.5 w-5.5" />
            <CountBadge count={item.badge && badges?.[item.badge]} help={item.badgeHelp} className="absolute -right-3 -top-2" />
          </span>
          {item.short || item.label}
        </NavLink>
      ))}
      <div className="relative flex flex-1" ref={ref}>
        {open && (
          <div className="panel elevated absolute bottom-full right-2 z-30 mb-2 w-64 p-2">
            {moreItems.map((item) => (
              <NavLink key={item.to} to={item.to} className={moreLinkClass}>
                <item.icon className="h-5 w-5 shrink-0" />
                <span className="flex-1">{item.label}</span>
                <CountBadge count={item.badge && badges?.[item.badge]} help={item.badgeHelp} />
              </NavLink>
            ))}
          </div>
        )}
        <button
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          className={`relative flex min-h-14 flex-1 flex-col items-center justify-center gap-1 text-xs font-medium ${
            open || moreActive ? 'text-primary' : 'text-muted-foreground'
          }`}
        >
          <span className="relative">
            <Menu className="h-5.5 w-5.5" />
            <CountBadge count={moreBadge} className="absolute -right-3 -top-2" />
          </span>
          Més
        </button>
      </div>
    </nav>
  )
}

function SessionExpiredBanner() {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-bear/30 bg-bear/10 px-4 py-3 sm:px-6 lg:px-10">
      <div className="flex items-start gap-3">
        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-bear" />
        <div>
          <p className="text-sm font-semibold text-bear">La connexió amb LaLiga Fantasy ha caducat</p>
          <p className="text-xs text-muted-foreground">
            Fins que no la tornis a connectar, les dades no s'actualitzen i no pots pujar, vendre ni pagar clàusules.
          </p>
        </div>
      </div>
      <Link to="/onboarding" className="flex min-h-10 items-center rounded-lg bg-bear/20 px-4 text-sm font-semibold text-bear hover:bg-bear/30">
        Tornar a connectar
      </Link>
    </div>
  )
}

function Toast({ toast, onClose }) {
  useEffect(() => {
    if (!toast) return undefined
    const id = setTimeout(onClose, 5000)
    return () => clearTimeout(id)
  }, [toast, onClose])

  if (!toast) return null
  return (
    <div className="fixed inset-x-3 bottom-20 z-50 flex justify-center md:bottom-6" role="status">
      <div
        className={`panel elevated flex max-w-md items-center gap-3 px-4 py-3 text-sm ${toast.tone === 'error' ? 'border-bear/40 text-bear' : 'border-bull/40 text-bull'}`}
      >
        <span className="flex-1">{toast.message}</span>
        <button onClick={onClose} aria-label="Tancar" className="text-muted-foreground hover:text-foreground">
          <X className="h-4 w-4" />
        </button>
      </div>
    </div>
  )
}

/** Remembers the newest existing alert so only alerts raised after this point pop up in the browser. */
async function markExistingAlertsNotified() {
  try {
    const { data } = await apiClient.get('/alerts')
    writeStored(window.localStorage, NOTIFIED_KEY, Math.max(0, ...data.data.map((a) => a.id)))
  } catch {
    // best effort
  }
}

/** Shows a browser notification for each unread alert newer than the last one notified. */
async function notifyNewAlerts(navigate) {
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return
  try {
    const { data } = await apiClient.get('/alerts')
    const lastNotified = readStored(window.localStorage, NOTIFIED_KEY, null)
    if (lastNotified === null) return markExistingAlertsNotified()
    const fresh = data.data.filter((a) => !a.read && a.id > lastNotified)
    if (fresh.length === 0) return
    writeStored(window.localStorage, NOTIFIED_KEY, Math.max(...fresh.map((a) => a.id)))
    fresh.slice(0, 3).forEach((a) => {
      const n = new Notification(a.title, { body: a.message, tag: `fantasy-alert-${a.id}` })
      n.onclick = () => {
        window.focus()
        if (a.link) navigate(a.link)
        n.close()
      }
    })
  } catch {
    // best effort
  }
}

export default function Layout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [header, setHeader] = useState(null)
  const [syncRequested, setSyncRequested] = useState(false)
  const [sessionExpired, setSessionExpired] = useState(false)
  const [toast, setToast] = useState(null)
  const prevHeader = useRef(null)
  useState(startVisitTracking)

  const loadHeader = useCallback(() => {
    return apiClient
      .get('/dashboard/header')
      .then((res) => {
        const next = res.data
        const prev = prevHeader.current
        prevHeader.current = next
        setHeader(next)
        setSessionExpired(Boolean(next.laligaSessionExpired))
        writeStored(window.localStorage, LAST_VISIT_KEY, new Date().toISOString())

        if (!prev) {
          // First load ever: don't pop notifications for alerts that were already there.
          if (readStored(window.localStorage, NOTIFIED_KEY, null) === null) markExistingAlertsNotified()
          return
        }
        // A sync (manual or scheduled) finished: every page reloads its data.
        if ((prev.syncing && !next.syncing) || (next.lastSyncedAt && prev.lastSyncedAt !== next.lastSyncedAt && !next.syncing)) {
          emitAppEvent(DATA_CHANGED)
          if (prev.syncing) setToast({ message: 'Dades actualitzades.', tone: 'success' })
        }
        if (next.unreadAlerts > prev.unreadAlerts) notifyNewAlerts(navigate)
      })
      .catch(() => {})
  }, [navigate])

  const syncing = syncRequested || Boolean(header?.syncing)

  useEffect(() => {
    loadHeader()
  }, [loadHeader])

  // Poll fast while a sync runs (to know when it ends), slowly otherwise (badges, new alerts).
  useEffect(() => {
    const id = setInterval(loadHeader, syncing ? SYNC_POLL_MS : HEADER_POLL_MS)
    return () => clearInterval(id)
  }, [syncing, loadHeader])

  const triggerSync = useCallback(async () => {
    if (syncing) return
    setSyncRequested(true)
    try {
      await apiClient.post('/fantasy/sync')
      await loadHeader()
    } catch (err) {
      if (err.response?.data?.code !== 'LALIGA_SESSION_EXPIRED') {
        setToast({ message: err.response?.data?.message || "No s'ha pogut iniciar la sincronització. Torna-ho a provar.", tone: 'error' })
      }
    } finally {
      setSyncRequested(false)
    }
  }, [syncing, loadHeader])

  useAppEvent(REQUEST_SYNC, triggerSync)
  useAppEvent(SESSION_EXPIRED, () => setSessionExpired(true))
  useAppEvent(ACTION_DONE, () => loadHeader())

  const closeToast = useCallback(() => setToast(null), [])

  return (
    <div className="flex min-h-screen bg-background text-foreground">
      <Sidebar badges={header?.badges} />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Never wraps: on mobile it keeps only logo, league, sync, bell and avatar. */}
        <header className="sticky top-0 z-30 flex flex-nowrap items-center justify-between gap-2 border-b border-border bg-surface/95 px-3 py-2.5 backdrop-blur sm:gap-3 sm:px-6 lg:px-10">
          <div className="flex min-w-0 flex-nowrap items-center gap-2 sm:gap-3">
            <Link to="/" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary md:hidden" aria-label="Avui">
              <LogoIcon className="h-5 w-5" />
            </Link>
            <LeagueSwitcher leagueName={header?.leagueName} />
            {header?.currentMatchday && (
              <span className="hidden items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground sm:flex">
                <Calendar className="h-4 w-4" />
                Jornada <span className="num font-semibold text-foreground">{header.currentMatchday}</span>
              </span>
            )}
          </div>

          <div className="flex shrink-0 flex-nowrap items-center gap-2 sm:gap-3">
            <HeaderClock />
            <SyncButton syncing={syncing} lastSyncedAt={header?.lastSyncedAt} onSync={triggerSync} />
            <NotificationBell unread={header?.unreadAlerts || 0} onChanged={loadHeader} />
            <UserMenu user={user} onLogout={logout} />
          </div>
        </header>

        {sessionExpired && <SessionExpiredBanner />}

        <main className="flex-1 px-4 py-6 pb-28 sm:px-6 md:pb-10 lg:px-10 lg:py-8">
          <Suspense fallback={<PageSkeleton />}>
            <Outlet context={{ header, refreshHeader: loadHeader }} />
          </Suspense>
        </main>
      </div>

      <MobileBottomNav badges={header?.badges} />
      <Toast toast={toast} onClose={closeToast} />
    </div>
  )
}
