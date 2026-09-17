import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import apiClient from '../api/client'
import { useEffect, useRef, useState } from 'react'
import { formatRelativeTime, initials } from '../utils/format'
import { LogoIcon } from './Icons'
import {
  Zap,
  Users,
  ShoppingBag,
  LayoutGrid,
  Shield,
  TrendingUp,
  Target,
  Trophy,
  BarChart3,
  Settings as SettingsIcon,
  RefreshCw,
  Bell,
  Calendar,
  ChevronDown,
  Menu,
} from 'lucide-react'

const NAV_ITEMS = [
  { to: '/', label: 'Avui', end: true, icon: Zap },
  { to: '/team', label: 'El meu equip', icon: Users },
  { to: '/market', label: 'Mercat', icon: ShoppingBag },
  { to: '/lineup', label: 'Alineació', icon: LayoutGrid },
  { to: '/clauses', label: 'Clàusules', icon: Shield },
  { to: '/trading', label: 'Trading', icon: TrendingUp },
  { to: '/players', label: 'Jugadors', icon: Target },
  { to: '/standings', label: 'Classificació', icon: Trophy },
  { to: '/history', label: 'Històric', icon: BarChart3 },
]

// The bottom tab bar can't fit all 9 destinations — this is the subset that
// covers the most common daily flow ("what should I do today" -> my squad
// -> the market -> clause opportunities), with everything else one tap away
// behind "Més".
const MOBILE_TAB_ITEMS = [
  { to: '/', label: 'Avui', end: true, icon: Zap },
  { to: '/team', label: 'Equip', icon: Users },
  { to: '/market', label: 'Mercat', icon: ShoppingBag },
  { to: '/clauses', label: 'Clàusules', icon: Shield },
]

function LeagueSwitcher({ leagueName }) {
  const [open, setOpen] = useState(false)
  const [leagues, setLeagues] = useState(null)
  const [activeLeagueId, setActiveLeagueId] = useState(null)
  const [switching, setSwitching] = useState(null)
  const ref = useRef(null)

  useEffect(() => {
    const onClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  const toggle = async () => {
    if (!open && !leagues) {
      const { data } = await apiClient.get('/leagues')
      setLeagues(data.data)
      setActiveLeagueId(data.activeLeagueId ?? null)
    }
    setOpen((o) => !o)
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
    <div className="relative" ref={ref}>
      <button
        onClick={toggle}
        className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium hover:border-primary/50"
      >
        <Trophy className="h-4 w-4 text-muted-foreground" />
        <span className="max-w-40 truncate">{leagueName || 'Sense lliga'}</span>
        <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
      </button>
      {open && (
        <div className="panel elevated absolute left-0 top-full z-20 mt-2 w-64 p-2">
          {!leagues && <p className="px-2 py-2 text-xs text-muted-foreground">Carregant…</p>}
          {leagues?.map((league) => (
            <button
              key={league.id}
              onClick={() => select(league)}
              disabled={switching === league.id}
              className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-raised ${
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

function NavMenu() {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const location = useLocation()

  useEffect(() => {
    const onClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  useEffect(() => setOpen(false), [location.pathname])

  const isSettings = location.pathname.startsWith('/settings')
  const current =
    (isSettings && { label: 'Configuració', icon: SettingsIcon }) ||
    NAV_ITEMS.find((item) => (item.end ? location.pathname === item.to : location.pathname.startsWith(item.to))) ||
    NAV_ITEMS[0]

  const linkClass = ({ isActive }) =>
    `flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
      isActive ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-surface-raised hover:text-foreground'
    }`

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium hover:border-primary/50"
      >
        <current.icon className="h-4 w-4 text-primary" />
        <span>{current.label}</span>
        <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
      </button>
      {open && (
        <div className="panel elevated absolute left-0 top-full z-30 mt-2 w-56 p-2">
          {NAV_ITEMS.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
              <item.icon className="h-4.5 w-4.5 shrink-0" />
              {item.label}
            </NavLink>
          ))}
          <div className="my-1 border-t border-border" />
          <NavLink to="/settings" className={linkClass}>
            <SettingsIcon className="h-4.5 w-4.5 shrink-0" />
            Configuració
          </NavLink>
        </div>
      )}
    </div>
  )
}

function MobileMoreMenu() {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const location = useLocation()

  useEffect(() => {
    const onClickOutside = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  useEffect(() => setOpen(false), [location.pathname])

  const moreItems = [...NAV_ITEMS.filter((item) => !MOBILE_TAB_ITEMS.some((t) => t.to === item.to)), { to: '/settings', label: 'Configuració', icon: SettingsIcon }]

  const linkClass = ({ isActive }) =>
    `flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
      isActive ? 'bg-primary/15 text-primary' : 'text-muted-foreground hover:bg-surface-raised hover:text-foreground'
    }`

  return (
    <div className="relative" ref={ref}>
      {open && (
        <div className="panel elevated absolute bottom-full right-0 z-30 mb-2 w-56 p-2">
          {moreItems.map((item) => (
            <NavLink key={item.to} to={item.to} className={linkClass}>
              <item.icon className="h-4.5 w-4.5 shrink-0" />
              {item.label}
            </NavLink>
          ))}
        </div>
      )}
      <button
        onClick={() => setOpen((o) => !o)}
        className={`flex flex-1 flex-col items-center gap-1 py-2 text-[11px] font-medium ${
          open ? 'text-primary' : 'text-muted-foreground'
        }`}
      >
        <Menu className="h-5 w-5" />
        Més
      </button>
    </div>
  )
}

function MobileBottomNav() {
  const tabClass = ({ isActive }) =>
    `flex flex-1 flex-col items-center gap-1 py-2 text-[11px] font-medium ${isActive ? 'text-primary' : 'text-muted-foreground'}`

  return (
    <nav className="panel elevated fixed inset-x-0 bottom-0 z-40 flex rounded-none border-x-0 border-b-0 md:hidden">
      {MOBILE_TAB_ITEMS.map((item) => (
        <NavLink key={item.to} to={item.to} end={item.end} className={tabClass}>
          <item.icon className="h-5 w-5" />
          {item.label}
        </NavLink>
      ))}
      <MobileMoreMenu />
    </nav>
  )
}

export default function Layout() {
  const { user, logout } = useAuth()
  const [syncing, setSyncing] = useState(false)
  const [header, setHeader] = useState(null)

  const loadHeader = () => {
    apiClient
      .get('/dashboard/today')
      .then((res) => setHeader(res.data))
      .catch(() => {})
  }

  useEffect(loadHeader, [])

  const triggerSync = async () => {
    setSyncing(true)
    try {
      await apiClient.post('/fantasy/sync')
    } catch {
      // surfaced elsewhere; keep the header interaction simple
    } finally {
      setTimeout(() => {
        setSyncing(false)
        loadHeader()
      }, 1500)
    }
  }

  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-3 sm:px-6 lg:px-10">
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2.5 pr-1">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary">
              <LogoIcon className="h-5 w-5" />
            </span>
            <p className="hidden text-sm font-bold leading-tight tracking-tight sm:block">Fantasy Assistant</p>
          </div>
          <div className="hidden md:block">
            <NavMenu />
          </div>
          <LeagueSwitcher leagueName={header?.leagueName} />
          {header?.currentMatchday && (
            <span className="hidden items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground sm:flex">
              <Calendar className="h-4 w-4" />
              Jornada <span className="num font-semibold text-foreground">{header.currentMatchday}</span>
            </span>
          )}
          <span className="hidden items-center gap-1.5 text-xs text-muted-foreground sm:flex">
            <span className="h-1.5 w-1.5 rounded-full bg-primary" />
            {header?.lastSyncedAt ? `Sincronitzat ${formatRelativeTime(header.lastSyncedAt)}` : 'Encara no sincronitzat'}
          </span>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={triggerSync}
            disabled={syncing}
            className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold hover:border-primary/50 disabled:opacity-50"
          >
            <RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            <span className="hidden sm:inline">{syncing ? 'Sincronitzant…' : 'Sincronitzar'}</span>
          </button>
          <button className="rounded-lg border border-border p-2 text-muted-foreground hover:border-primary/50">
            <Bell className="h-4 w-4" />
          </button>
          <div className="group relative">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary">
              {initials(user?.name)}
            </div>
            <div className="panel elevated invisible absolute right-0 top-full z-20 mt-2 w-40 p-1 opacity-0 transition-opacity group-hover:visible group-hover:opacity-100">
              <p className="truncate px-2 py-1.5 text-xs text-muted-foreground">{user?.email}</p>
              <button
                onClick={logout}
                className="w-full rounded-md px-2 py-1.5 text-left text-sm font-semibold text-bear hover:bg-bear/10"
              >
                Sortir
              </button>
            </div>
          </div>
        </div>
      </header>

      <main className="px-4 py-6 pb-24 sm:px-6 md:pb-10 lg:px-10 lg:py-10">
        <Outlet context={{ header, refreshHeader: loadHeader }} />
      </main>

      <MobileBottomNav />
    </div>
  )
}
