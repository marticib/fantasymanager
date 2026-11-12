import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { PageSkeleton, ErrorState } from '../components/PageState'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'

export default function Standings() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')

  const load = () => {
    setError('')
    apiClient
      .get('/standings')
      .then((res) => setData(res.data))
      .catch((err) => setError(err.response?.data?.message || "No s'ha pogut carregar la classificació."))
  }

  useEffect(load, [])
  useAppEvent(DATA_CHANGED, load)

  if (error && !data) return <ErrorState message={error} onRetry={load} />
  if (!data) return <PageSkeleton cards={0} rows={8} />

  return (
    <div>
      <h1 className="text-3xl font-semibold tracking-tight">Classificació</h1>
      {data.message && <p className="mt-2 text-sm text-muted-foreground">{data.message}</p>}
      <div className="panel mt-4 overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border text-left text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
              <th className="px-4 py-3">#</th>
              <th className="px-4 py-3">Equip</th>
              <th className="px-4 py-3">Punts</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {data.data.map((row) => (
              <tr key={row.position} className={row.isMine ? 'bg-primary/10' : ''}>
                <td className="num px-4 py-2.5 text-muted-foreground">{row.position}</td>
                <td className="px-4 py-2.5 font-medium">
                  {row.teamId ? (
                    <Link to={row.isMine ? '/team' : `/standings/${row.teamId}`} className="hover:text-primary hover:underline">
                      {row.team}
                    </Link>
                  ) : (
                    row.team
                  )}
                </td>
                <td className="num px-4 py-2.5">{row.points}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
