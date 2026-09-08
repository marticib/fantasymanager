import { useEffect, useState } from 'react'
import apiClient from '../api/client'

export default function Standings() {
  const [data, setData] = useState(null)

  useEffect(() => {
    apiClient.get('/standings').then((res) => setData(res.data))
  }, [])

  if (!data) return <p className="text-text-muted">Carregant…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Classificació</h1>
      {data.message && <p className="mt-2 text-sm text-text-muted">{data.message}</p>}
      <div className="mt-4 overflow-hidden rounded-2xl border border-border bg-surface">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-text-muted">
              <th className="px-4 py-3">#</th>
              <th className="px-4 py-3">Equip</th>
              <th className="px-4 py-3">Punts</th>
            </tr>
          </thead>
          <tbody>
            {data.data.map((row) => (
              <tr
                key={row.position}
                className={`border-b border-border last:border-0 ${row.isMine ? 'bg-accent/10' : ''}`}
              >
                <td className="px-4 py-3">{row.position}</td>
                <td className="px-4 py-3 font-medium">{row.team}</td>
                <td className="px-4 py-3">{row.points}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
