import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import apiClient from '../api/client'
import { useEffect, useRef, useState } from 'react'
import { formatRelativeTime, initials } from '../utils/format'
import {
  BoltIcon,
  UsersIcon,
  ShopIcon,
  GridIcon,
  ShieldIcon,
  TrendingUpIcon,
  TargetIcon,
  TrophyIcon,
  BarChartIcon,
  GearIcon,
  RefreshIcon,
  BellIcon,
  CalendarIcon,
  ChevronDownIcon,
  LogoIcon,
} from './Icons'

const NAV_ITEMS = [
  { to: '/', label: 'Avui', end: true, icon: BoltIcon },
  { to: '/team', label: 'El meu equip', icon: UsersIcon },
  { to: '/market', label: 'Mercat', icon: ShopIcon },
  { to: '/lineup', label: 'Alineació', icon: GridIcon },
  { to: '/clauses', label: 'Clàusules', icon: ShieldIcon },
  { to: '/trading', label: 'Trading', icon: TrendingUpIcon },
  { to: '/players', label: 'Jugadors', icon: TargetIcon },
  { to: '/standings', label: 'Classificació', icon: TrophyIcon },
  { to: '/history', label: 'Històric', icon: BarChartIcon },
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
        className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium hover:border-accent/50"
      >
        <TrophyIcon className="h-4 w-4 text-trading" />
        <span className="max-w-40 truncate">{leagueName || 'Sense lliga'}</span>
        <ChevronDownIcon className="h-3.5 w-3.5 text-text-muted" />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-20 mt-2 w-64 rounded-xl border border-border bg-surface p-2 shadow-xl">
          {!leagues && <p className="px-2 py-2 text-xs text-text-muted">Carregant…</p>}
          {leagues?.map((league) => (
            <button
              key={league.id}
              onClick={() => select(league)}
              disabled={switching === league.id}
              className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-hover ${
                league.id === activeLeagueId ? 'text-accent' : ''
              }`}
            >
              <span className="truncate">{league.name}</span>
              {league.id === activeLeagueId && <span className="text-xs">✓</span>}
            </button>
          ))}
          {leagues?.length === 0 && <p className="px-2 py-2 text-xs text-text-muted">Cap lliga detectada.</p>}
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
    (isSettings && { label: 'Configuració', icon: GearIcon }) ||
    NAV_ITEMS.find((item) => (item.end ? location.pathname === item.to : location.pathname.startsWith(item.to))) ||
    NAV_ITEMS[0]

  const linkClass = ({ isActive }) =>
    `flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
      isActive ? 'bg-accent/15 text-accent' : 'text-text-muted hover:bg-surface-hover hover:text-text'
    }`

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium hover:border-accent/50"
      >
        <current.icon className="h-4 w-4 text-accent" />
        <span>{current.label}</span>
        <ChevronDownIcon className="h-3.5 w-3.5 text-text-muted" />
      </button>
      {open && (
        <div className="absolute left-0 top-full z-30 mt-2 w-56 rounded-xl border border-border bg-surface p-2 shadow-xl">
          {NAV_ITEMS.map((item) => (
            <NavLink key={item.to} to={item.to} end={item.end} className={linkClass}>
              <item.icon className="h-4.5 w-4.5 shrink-0" />
              {item.label}
            </NavLink>
          ))}
          <div className="my-1 border-t border-border" />
          <NavLink to="/settings" className={linkClass}>
            <GearIcon className="h-4.5 w-4.5 shrink-0" />
            Configuració
          </NavLink>
        </div>
      )}
    </div>
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
    <div className="min-h-screen bg-bg text-text">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-border bg-surface px-4 py-3 sm:px-6 lg:px-10">
        <div className="flex flex-wrap items-center gap-3">
          <div className="flex items-center gap-2.5 pr-1">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-buy/15 text-buy">
              <LogoIcon className="h-5 w-5" />
            </span>
            <p className="hidden text-sm font-bold leading-tight tracking-tight sm:block">Fantasy Assistant</p>
          </div>
          <NavMenu />
          <LeagueSwitcher leagueName={header?.leagueName} />
          {header?.currentMatchday && (
            <span className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-text-muted">
              <CalendarIcon className="h-4 w-4" />
              Jornada <span className="font-semibold text-text">{header.currentMatchday}</span>
            </span>
          )}
          <span className="flex items-center gap-1.5 text-xs text-text-muted">
            <span className="h-1.5 w-1.5 rounded-full bg-trading" />
            {header?.lastSyncedAt ? `Sincronitzat ${formatRelativeTime(header.lastSyncedAt)}` : 'Encara no sincronitzat'}
          </span>
        </div>

        <div className="flex items-center gap-3">
          <button
            onClick={triggerSync}
            disabled={syncing}
            className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold hover:border-accent/50 disabled:opacity-50"
          >
            <RefreshIcon className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
            {syncing ? 'Sincronitzant…' : 'Sincronitzar'}
          </button>
          <button className="rounded-lg border border-border p-2 text-text-muted hover:border-accent/50">
            <BellIcon className="h-4 w-4" />
          </button>
          <div className="group relative">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-accent/15 text-xs font-bold text-accent">
              {initials(user?.name)}
            </div>
            <div className="invisible absolute right-0 top-full z-20 mt-2 w-40 rounded-lg border border-border bg-surface p-1 opacity-0 shadow-xl transition-opacity group-hover:visible group-hover:opacity-100">
              <p className="truncate px-2 py-1.5 text-xs text-text-muted">{user?.email}</p>
              <button
                onClick={logout}
                className="w-full rounded-md px-2 py-1.5 text-left text-sm font-semibold text-sell hover:bg-sell/10"
              >
                Sortir
              </button>
            </div>
          </div>
        </div>
      </header>

      <main className="px-4 py-6 sm:px-6 lg:px-10 lg:py-10">
        <Outlet context={{ header, refreshHeader: loadHeader }} />
      </main>
    </div>
  )
}
