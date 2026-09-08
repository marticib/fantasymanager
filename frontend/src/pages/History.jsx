import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts'
import apiClient from '../api/client'
import { ChevronDownIcon } from '../components/Icons'
import { formatMoney, formatMoneyM, formatDelta, formatPercent, formatFullDate, POSITION_LABELS, initials } from '../utils/format'

function ChangeBadge({ change, changePct }) {
  if (change === null || change === undefined) return <span className="text-xs text-text-muted">—</span>
  const positive = change > 0
  const neutral = change === 0
  const colorClass = neutral ? 'text-text-muted' : positive ? 'text-buy' : 'text-sell'
  return (
    <span className={`text-xs font-semibold ${colorClass}`}>
      {formatDelta(change)}
      {changePct !== null && changePct !== undefined && <span className="text-text-muted"> ({formatPercent(changePct)})</span>}
    </span>
  )
}

function DayRow({ row }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="border-b border-border last:border-0">
      <button
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-hover"
      >
        <div className="flex items-center gap-3">
          <ChevronDownIcon className={`h-4 w-4 shrink-0 text-text-muted transition-transform ${open ? 'rotate-180' : ''}`} />
          <div>
            <p className="text-sm font-medium capitalize">{formatFullDate(new Date(row.day))}</p>
            <p className="text-xs text-text-muted">{row.player_count} jugadors</p>
          </div>
        </div>
        <div className="text-right">
          <p className="text-sm font-bold">{formatMoneyM(row.team_value)}</p>
          <ChangeBadge change={row.team_value_change} changePct={row.team_value_change_pct} />
        </div>
      </button>
      {open && (
        <div className="border-t border-border bg-bg/40 px-4 py-2">
          {row.players.map((p) => (
            <Link
              key={p.id}
              to={`/players/${p.id}`}
              className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 text-sm hover:bg-surface-hover"
            >
              <div className="flex min-w-0 items-center gap-2.5">
                <div className="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded-full bg-hold/15 text-[10px] font-bold text-hold">
                  {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
                </div>
                <span className="truncate">{p.name}</span>
                <span className="shrink-0 rounded-md border border-border px-1.5 py-0.5 text-[10px] font-semibold text-text-muted">
                  {POSITION_LABELS[p.position] || p.position || '—'}
                </span>
              </div>
              <div className="shrink-0 text-right">
                <p className="font-medium text-text-muted">{formatMoneyM(p.marketValue)}</p>
                <ChangeBadge change={p.change} changePct={p.changePct} />
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

export default function History() {
  const [rows, setRows] = useState(null)
  const [message, setMessage] = useState('')

  useEffect(() => {
    apiClient.get('/history/team-value').then((res) => {
      setRows(res.data.data)
      setMessage(res.data.message || '')
    })
  }, [])

  const rowsNewestFirst = useMemo(() => (rows ? [...rows].reverse() : []), [rows])

  if (!rows) return <p className="text-text-muted">Carregant…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Històric</h1>
      <p className="mt-1 text-sm text-text-muted">Evolució del valor de la plantilla dia a dia · últims 30 dies.</p>
      {message && <p className="mt-2 text-sm text-text-muted">{message}</p>}

      <div className="mt-6 rounded-2xl border border-border bg-surface p-5">
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={rows}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
              <XAxis dataKey="day" stroke="var(--color-text-muted)" fontSize={11} />
              <YAxis stroke="var(--color-text-muted)" fontSize={11} tickFormatter={(v) => `${(v / 1_000_000).toFixed(1)}M`} />
              <Tooltip
                contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)' }}
                formatter={(v) => formatMoney(v)}
              />
              <Line type="monotone" dataKey="team_value" stroke="var(--color-accent)" strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>

      <div className="mt-4 overflow-hidden rounded-2xl border border-border bg-surface">
        <div className="border-b border-border px-4 py-3">
          <p className="text-xs font-semibold uppercase tracking-wide text-text-muted">
            Valor per dia · desplega per veure el desglossament per jugador
          </p>
        </div>
        {rowsNewestFirst.map((row) => (
          <DayRow key={row.day} row={row} />
        ))}
        {rowsNewestFirst.length === 0 && <p className="p-6 text-center text-sm text-text-muted">Encara no hi ha historial.</p>}
      </div>
    </div>
  )
}
