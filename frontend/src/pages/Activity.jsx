import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Activity as ActivityIcon, ArrowLeftRight, Gift, ShoppingBag, Tag } from 'lucide-react'
import { cachedGet } from '../api/cachedGet'
import { PageSkeleton, ErrorState, EmptyState } from '../components/PageState'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { usePersistentState } from '../lib/storage'
import { formatMoneyM, initials } from '../utils/format'

const PERIODS = [
  { days: 1, label: 'Avui' },
  { days: 7, label: '7 dies' },
  { days: 30, label: '30 dies' },
]

const TYPE_META = {
  MARKET_BUY: { icon: ShoppingBag, className: 'bg-bull/15 text-bull' },
  MARKET_SELL: { icon: Tag, className: 'bg-bear/15 text-bear' },
  TRANSFER: { icon: ArrowLeftRight, className: 'bg-info/15 text-info' },
  MATCHDAY_REWARD: { icon: Gift, className: 'bg-warn/15 text-warn' },
}

const time = (iso) => new Intl.DateTimeFormat('ca-ES', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
const dayLabel = (iso) => {
  const d = new Date(iso)
  const today = new Date()
  const yesterday = new Date(Date.now() - 864e5)
  if (d.toDateString() === today.toDateString()) return 'Avui'
  if (d.toDateString() === yesterday.toDateString()) return 'Ahir'
  const text = new Intl.DateTimeFormat('ca-ES', { weekday: 'long', day: 'numeric', month: 'long' }).format(d)
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function TeamName({ team }) {
  if (!team) return <span className="text-muted-foreground">un equip</span>
  return <strong className={team.isMine ? 'text-primary' : 'text-foreground'}>{team.isMine ? 'Tu' : team.name}</strong>
}

function PlayerName({ player }) {
  if (!player) return <span>un jugador</span>
  return (
    <Link to={`/players/${player.id}`} className="font-semibold text-foreground hover:text-primary">
      {player.name}
    </Link>
  )
}

/** One move, as a sentence. */
function Sentence({ item }) {
  switch (item.type) {
    case 'MARKET_BUY':
      return (
        <>
          <TeamName team={item.to} /> {item.to?.isMine ? 'has comprat' : 'ha comprat'} <PlayerName player={item.player} /> al mercat
        </>
      )
    case 'MARKET_SELL':
      return (
        <>
          <TeamName team={item.from} /> {item.from?.isMine ? 'has venut' : 'ha venut'} <PlayerName player={item.player} /> al mercat
        </>
      )
    case 'TRANSFER':
      return (
        <>
          <TeamName team={item.to} /> {item.to?.isMine ? 'has pres' : 'ha pres'} <PlayerName player={item.player} /> a{' '}
          {item.from?.isMine ? <strong className="text-bear">tu</strong> : <TeamName team={item.from} />}
        </>
      )
    case 'MATCHDAY_REWARD':
      return (
        <>
          <TeamName team={item.to} /> {item.to?.isMine ? 'has cobrat' : 'ha cobrat'} el premi de la jornada{item.week ? ` ${item.week}` : ''}
        </>
      )
    default:
      return null
  }
}

function FeedItem({ item }) {
  const meta = TYPE_META[item.type] || TYPE_META.MARKET_BUY
  const lostByMe = item.type === 'TRANSFER' && item.from?.isMine
  return (
    <div className={`flex items-center gap-3 px-4 py-3 ${lostByMe ? 'bg-bear/5' : ''}`}>
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${meta.className}`}>
        <meta.icon className="h-4 w-4" />
      </span>
      {item.player?.imageUrl ? (
        <img src={item.player.imageUrl} alt="" className="hidden h-9 w-9 shrink-0 rounded-full bg-surface-raised object-cover sm:block" />
      ) : item.player ? (
        <span className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-bold text-muted-foreground sm:flex">
          {initials(item.player.name)}
        </span>
      ) : null}
      <p className="min-w-0 flex-1 text-sm text-muted-foreground">
        <Sentence item={item} />
      </p>
      <div className="shrink-0 text-right">
        <p className="num text-sm font-semibold">{formatMoneyM(item.amount)}</p>
        <p className="text-[11px] text-muted-foreground">{time(item.at)}</p>
      </div>
    </div>
  )
}

function TeamCard({ team, active, onClick }) {
  return (
    <button
      onClick={onClick}
      aria-pressed={active}
      className={`panel min-w-56 shrink-0 p-4 text-left sm:min-w-0 ${active ? 'border-primary/60 ring-1 ring-primary/40' : 'hover:border-border-strong'} ${team.isMine ? 'bg-primary/5' : ''}`}
    >
      <p className="truncate font-semibold">{team.isMine ? `${team.name} (tu)` : team.name}</p>
      <dl className="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
        <dt className="text-muted-foreground">Gastat</dt>
        <dd className="num text-right">{formatMoneyM(team.spent)}</dd>
        <dt className="text-muted-foreground">Ingressat</dt>
        <dd className="num text-right">{formatMoneyM(team.earned + team.rewards)}</dd>
        <dt className="text-muted-foreground">Fitxatges</dt>
        <dd className="num text-right">
          {team.signings}
          {team.fromRivals > 0 && <span className="text-info"> ({team.fromRivals} a rivals)</span>}
        </dd>
        <dt className="text-muted-foreground">Perduts a rivals</dt>
        <dd className={`num text-right ${team.lostToRivals > 0 ? 'text-bear' : ''}`}>{team.lostToRivals}</dd>
      </dl>
    </button>
  )
}

export default function Activity() {
  const [days, setDays] = usePersistentState('activity-days', 7)
  const [teamFilter, setTeamFilter] = useState(null)
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  const load = () => {
    setError('')
    cachedGet('/activity', { days }, setData).catch((err) => setError(err.response?.data?.message || "No s'ha pogut carregar l'activitat."))
  }

  useEffect(load, [days])
  useAppEvent(DATA_CHANGED, load)

  const items = useMemo(() => {
    const list = data?.data || []
    return teamFilter ? list.filter((i) => i.from?.id === teamFilter || i.to?.id === teamFilter) : list
  }, [data, teamFilter])

  const groups = useMemo(() => {
    const out = []
    for (const item of items) {
      const label = dayLabel(item.at)
      if (!out.length || out[out.length - 1].label !== label) out.push({ label, items: [] })
      out[out.length - 1].items.push(item)
    }
    return out
  }, [items])

  if (error && !data) return <ErrorState message={error} onRetry={load} />
  if (!data) return <PageSkeleton cards={3} rows={6} />

  const activeTeams = (data.teams || []).filter((t) => t.signings || t.sales || t.lostToRivals || t.rewards)

  return (
    <div>
      <h1 className="text-3xl font-bold tracking-tight">Activitat</h1>
      <p className="mt-1 text-sm text-muted-foreground">Què han fet tots els equips de la lliga: compres, vendes, jugadors presos a rivals i premis.</p>

      <div className="mt-4 inline-flex gap-1 rounded-full border border-border bg-surface p-1" role="tablist">
        {PERIODS.map((p) => (
          <button
            key={p.days}
            role="tab"
            aria-selected={days === p.days}
            onClick={() => setDays(p.days)}
            className={`min-h-9 rounded-full px-4 text-sm font-semibold ${days === p.days ? 'bg-primary text-background' : 'text-muted-foreground hover:text-foreground'}`}
          >
            {p.label}
          </button>
        ))}
      </div>

      {activeTeams.length > 0 && (
        <div className="stagger -mx-4 mt-4 flex gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 lg:grid-cols-4">
          {activeTeams.map((t) => (
            <TeamCard key={t.id} team={t} active={teamFilter === t.id} onClick={() => setTeamFilter(teamFilter === t.id ? null : t.id)} />
          ))}
        </div>
      )}
      {teamFilter && (
        <button onClick={() => setTeamFilter(null)} className="mt-3 text-sm font-semibold text-primary hover:underline">
          Veure tots els equips
        </button>
      )}

      {groups.length === 0 ? (
        <EmptyState icon={ActivityIcon} className="mt-4" title="Cap moviment en aquest període" message="Prova amb un període més llarg." />
      ) : (
        <div key={`${days}-${teamFilter}`} className="mt-4 space-y-4">
          {groups.map((g) => (
            <section key={g.label} className="panel overflow-hidden">
              <h2 className="border-b border-border bg-surface-raised/40 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {g.label}
              </h2>
              <div className="stagger divide-y divide-border">
                {g.items.map((item) => (
                  <FeedItem key={item.id} item={item} />
                ))}
              </div>
            </section>
          ))}
        </div>
      )}
    </div>
  )
}
