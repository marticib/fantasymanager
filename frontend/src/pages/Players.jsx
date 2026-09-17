import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import Sparkline from '../components/Sparkline'
import { SearchIcon } from '../components/Icons'
import { formatMoneyM, formatDelta, POSITION_LABELS, PLAYER_TYPE_LABEL, classifyPlayerType, initials } from '../utils/format'

const POSITIONS = ['GK', 'DF', 'MF', 'FW']

const TYPE_CLASS = {
  ESPORTIU: 'border-bull/40 text-bull',
  TRADING: 'border-warn/40 text-warn',
  HIBRID: 'border-primary/40 text-primary',
}

function classifyType(player) {
  return classifyPlayerType(player.trend?.classification, player.averagePoints)
}

function scoreBadgeClass(score) {
  if (score >= 80) return 'bg-bull/15 text-bull'
  if (score >= 65) return 'bg-primary/15 text-primary'
  return 'bg-warn/15 text-warn'
}

const SORT_ACCESSORS = {
  player: (p) => p.name,
  position: (p) => p.position,
  type: (p) => PLAYER_TYPE_LABEL[classifyType(p)],
  marketValue: (p) => p.marketValue,
  change24h: (p) => p.trend?.change24h,
  owner: (p) => (p.owner ? (p.owner.isMine ? 'Tu' : p.owner.name) : 'Lliure'),
  clauseValue: (p) => p.clauseValue,
  fantasyScore: (p) => p.fantasyScore,
}

function PlayerCard({ p }) {
  const type = classifyType(p)
  const change24h = p.trend?.change24h

  return (
    <Link to={`/players/${p.id}`} className="panel block p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-border-strong">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
            {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold">{p.name}</p>
            <p className="truncate text-xs text-muted-foreground">
              {p.club || 'Club desconegut'} · {POSITION_LABELS[p.position] || p.position}
            </p>
          </div>
        </div>
        <span className={`num shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${scoreBadgeClass(p.fantasyScore)}`}>
          {Math.round(p.fantasyScore)}
        </span>
      </div>

      <div className="mt-3 flex items-end justify-between gap-3">
        <div>
          <p className="num text-2xl font-semibold">{formatMoneyM(p.marketValue)}</p>
          {change24h != null && (
            <span
              className={`num mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                change24h > 0 ? 'bg-bull-soft text-bull' : change24h < 0 ? 'bg-bear-soft text-bear' : 'bg-surface-raised text-muted-foreground'
              }`}
            >
              {change24h >= 0 ? '↗' : '↘'} {formatDelta(change24h)}
            </span>
          )}
        </div>
        {p.history?.length > 1 && (
          <div className="h-8 w-24 shrink-0">
            <Sparkline data={p.history} />
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between gap-2 border-t border-border pt-3">
        <span className={`rounded-md border px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide ${TYPE_CLASS[type]}`}>
          {PLAYER_TYPE_LABEL[type]}
        </span>
        <span className="text-xs text-muted-foreground">{p.owner ? (p.owner.isMine ? 'Tu' : p.owner.name) : 'Lliure'}</span>
        {p.clauseValue != null && <span className="num text-xs font-semibold text-primary">{formatMoneyM(p.clauseValue)}</span>}
      </div>
    </Link>
  )
}

export default function Players() {
  const [players, setPlayers] = useState(null)
  const [total, setTotal] = useState(null)
  const [error, setError] = useState('')
  const [position, setPosition] = useState('')
  const [search, setSearch] = useState('')
  const [sort, setSort] = useState({ key: 'fantasyScore', direction: 'desc' })

  useEffect(() => {
    const handle = setTimeout(() => {
      apiClient
        .get('/players', { params: { position: position || undefined, search: search || undefined, per_page: 60 } })
        .then((res) => {
          setPlayers(res.data.data)
          setTotal(res.data.total ?? res.data.data.length)
        })
        .catch((err) => setError(err.response?.data?.message || 'Error carregant els jugadors.'))
    }, 250)
    return () => clearTimeout(handle)
  }, [position, search])

  const sorted = useMemo(() => {
    if (!players) return []
    const dir = sort.direction === 'asc' ? 1 : -1
    const accessor = SORT_ACCESSORS[sort.key] || SORT_ACCESSORS.fantasyScore
    return [...players].sort((a, b) => {
      const av = accessor(a)
      const bv = accessor(b)
      if (av === null || av === undefined || av === '') return 1
      if (bv === null || bv === undefined || bv === '') return -1
      if (typeof av === 'string') return av.localeCompare(bv) * dir
      return av > bv ? dir : av < bv ? -dir : 0
    })
  }, [players, sort])

  const toggleSort = (key) => {
    setSort((s) => (s.key === key ? { key, direction: s.direction === 'desc' ? 'asc' : 'desc' } : { key, direction: 'desc' }))
  }

  const sortArrow = (key) => (sort.key === key ? (sort.direction === 'desc' ? ' ↓' : ' ↑') : '')

  if (error) return <p className="text-bear">{error}</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Jugadors</h1>
      <p className="mt-1 text-sm text-muted-foreground">{total ?? '…'} jugadors indexats a la lliga</p>

      <div className="mt-4 flex items-center gap-2 rounded-2xl border border-border bg-surface px-4 py-3">
        <SearchIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
        <input
          placeholder="Buscar per nom o equip"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
        />
        <select
          value={position}
          onChange={(e) => setPosition(e.target.value)}
          className="shrink-0 rounded-lg border border-border bg-background px-2 py-1.5 text-sm"
        >
          <option value="">Totes les posicions</option>
          {POSITIONS.map((p) => (
            <option key={p} value={p}>
              {POSITION_LABELS[p]}
            </option>
          ))}
        </select>
      </div>

      <div className="mt-4 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-240 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('player')}>
                Jugador{sortArrow('player')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('position')}>
                Pos.{sortArrow('position')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('type')}>
                Tipus{sortArrow('type')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('marketValue')}>
                Valor{sortArrow('marketValue')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('change24h')}>
                24h{sortArrow('change24h')}
              </th>
              <th className="px-4 py-3">7d</th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('owner')}>
                Propietari{sortArrow('owner')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('clauseValue')}>
                Clàusula{sortArrow('clauseValue')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('fantasyScore')}>
                Score{sortArrow('fantasyScore')}
              </th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((p) => {
              const type = classifyType(p)
              const change24h = p.trend?.change24h
              return (
                <tr key={p.id} className="border-b border-border last:border-0 hover:bg-surface-raised">
                  <td className="px-4 py-3">
                    <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-primary">
                      <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
                        {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
                      </div>
                      <div className="min-w-0">
                        <p className="truncate font-medium">{p.name}</p>
                        <p className="truncate text-xs text-muted-foreground">{p.club || 'Club desconegut'}</p>
                      </div>
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
                      {POSITION_LABELS[p.position] || p.position}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`rounded-md border px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide ${TYPE_CLASS[type]}`}>
                      {PLAYER_TYPE_LABEL[type]}
                    </span>
                  </td>
                  <td className="px-4 py-3 font-medium">{formatMoneyM(p.marketValue)}</td>
                  <td className={`px-4 py-3 ${change24h > 0 ? 'text-bull' : change24h < 0 ? 'text-bear' : 'text-muted-foreground'}`}>
                    {change24h != null ? (
                      <span className="inline-flex items-center gap-1 rounded-md bg-current/10 px-1.5 py-0.5 text-xs font-semibold">
                        {change24h >= 0 ? '↗' : '↘'} {formatDelta(change24h)}
                      </span>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="w-24 px-4 py-3">
                    <Sparkline data={p.history} />
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{p.owner ? (p.owner.isMine ? 'Tu' : p.owner.name) : 'Lliure'}</td>
                  <td className="px-4 py-3 text-primary">{p.clauseValue ? formatMoneyM(p.clauseValue) : '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(p.fantasyScore)}`}>
                      {Math.round(p.fantasyScore)}
                    </span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
        {sorted.length === 0 && players && <p className="p-6 text-center text-sm text-muted-foreground">Cap jugador trobat.</p>}
        {!players && <p className="p-6 text-center text-sm text-muted-foreground">Carregant…</p>}
      </div>

      <div className="mt-4 space-y-3 md:hidden">
        {sorted.map((p) => (
          <PlayerCard key={p.id} p={p} />
        ))}
        {sorted.length === 0 && players && <p className="panel p-6 text-center text-sm text-muted-foreground">Cap jugador trobat.</p>}
        {!players && <p className="panel p-6 text-center text-sm text-muted-foreground">Carregant…</p>}
      </div>
    </div>
  )
}
