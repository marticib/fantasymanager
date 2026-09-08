import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import Sparkline from '../components/Sparkline'
import { SearchIcon } from '../components/Icons'
import { formatMoneyM, formatDelta, POSITION_LABELS, PLAYER_TYPE_LABEL, classifyPlayerType, initials } from '../utils/format'

const POSITIONS = ['GK', 'DF', 'MF', 'FW']

const TYPE_CLASS = {
  ESPORTIU: 'border-buy/40 text-buy',
  TRADING: 'border-trading/40 text-trading',
  HIBRID: 'border-accent/40 text-accent',
}

function classifyType(player) {
  return classifyPlayerType(player.trend?.classification, player.averagePoints)
}

function scoreBadgeClass(score) {
  if (score >= 80) return 'bg-buy/15 text-buy'
  if (score >= 65) return 'bg-accent/15 text-accent'
  return 'bg-trading/15 text-trading'
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

  if (error) return <p className="text-sell">{error}</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Jugadors</h1>
      <p className="mt-1 text-sm text-text-muted">{total ?? '…'} jugadors indexats a la lliga</p>

      <div className="mt-4 flex items-center gap-2 rounded-2xl border border-border bg-surface px-4 py-3">
        <SearchIcon className="h-4 w-4 shrink-0 text-text-muted" />
        <input
          placeholder="Buscar per nom o equip"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full bg-transparent text-sm outline-none placeholder:text-text-muted"
        />
        <select
          value={position}
          onChange={(e) => setPosition(e.target.value)}
          className="shrink-0 rounded-lg border border-border bg-bg px-2 py-1.5 text-sm"
        >
          <option value="">Totes les posicions</option>
          {POSITIONS.map((p) => (
            <option key={p} value={p}>
              {POSITION_LABELS[p]}
            </option>
          ))}
        </select>
      </div>

      <div className="mt-4 overflow-x-auto rounded-2xl border border-border bg-surface">
        <table className="w-full min-w-240 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-text-muted">
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('player')}>
                Jugador{sortArrow('player')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('position')}>
                Pos.{sortArrow('position')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('type')}>
                Tipus{sortArrow('type')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('marketValue')}>
                Valor{sortArrow('marketValue')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('change24h')}>
                24h{sortArrow('change24h')}
              </th>
              <th className="px-4 py-3">7d</th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('owner')}>
                Propietari{sortArrow('owner')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('clauseValue')}>
                Clàusula{sortArrow('clauseValue')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-text" onClick={() => toggleSort('fantasyScore')}>
                Score{sortArrow('fantasyScore')}
              </th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((p) => {
              const type = classifyType(p)
              const change24h = p.trend?.change24h
              return (
                <tr key={p.id} className="border-b border-border last:border-0 hover:bg-surface-hover">
                  <td className="px-4 py-3">
                    <Link to={`/players/${p.id}`} className="flex items-center gap-2.5 hover:text-accent">
                      <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-hold/15 text-[10px] font-bold text-hold">
                        {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
                      </div>
                      <div className="min-w-0">
                        <p className="truncate font-medium">{p.name}</p>
                        <p className="truncate text-xs text-text-muted">{p.club || 'Club desconegut'}</p>
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
                  <td className={`px-4 py-3 ${change24h > 0 ? 'text-buy' : change24h < 0 ? 'text-sell' : 'text-text-muted'}`}>
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
                  <td className="px-4 py-3 text-text-muted">{p.owner ? (p.owner.isMine ? 'Tu' : p.owner.name) : 'Lliure'}</td>
                  <td className="px-4 py-3 text-accent">{p.clauseValue ? formatMoneyM(p.clauseValue) : '—'}</td>
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
        {sorted.length === 0 && players && <p className="p-6 text-center text-sm text-text-muted">Cap jugador trobat.</p>}
        {!players && <p className="p-6 text-center text-sm text-text-muted">Carregant…</p>}
      </div>
    </div>
  )
}
