import { useEffect, useState } from 'react'
import apiClient from '../api/client'

function PlayerRow({ row }) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-border px-4 py-2">
      <div>
        <p className="text-sm font-medium">{row.player.name}</p>
        <p className="text-xs text-text-muted">
          {row.player.position} · {row.player.club}
        </p>
      </div>
      <div className="flex items-center gap-3">
        {row.availability === 'DUBTE' && (
          <span className="rounded-md bg-trading/15 px-2 py-0.5 text-xs font-bold text-trading">DUBTE</span>
        )}
        <span className="text-sm font-semibold">{row.fantasyScore}</span>
      </div>
    </div>
  )
}

export default function Lineup() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    apiClient
      .get('/team/lineup')
      .then((res) => setData(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant l\'alineació.'))
  }, [])

  if (error) return <p className="text-sell">{error}</p>
  if (!data) return <p className="text-text-muted">Carregant…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Alineació recomanada</h1>
      <p className="mt-1 text-sm text-text-muted">Rànquing per Fantasy Score sobre un 1-4-4-2 base.</p>

      <div className="mt-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-text-muted">Titular</h2>
        <div className="mt-3 space-y-2">
          {data.starters.map((row) => (
            <PlayerRow key={row.player.id} row={row} />
          ))}
        </div>
      </div>

      <div className="mt-8">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-text-muted">Banqueta</h2>
        <div className="mt-3 space-y-2">
          {data.bench.map((row) => (
            <PlayerRow key={row.player.id} row={row} />
          ))}
        </div>
      </div>
    </div>
  )
}
