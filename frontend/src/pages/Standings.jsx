import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { PageSkeleton, ErrorState, EmptyState } from '../components/PageState'
import ChangeBadge from '../components/ChangeBadge'
import InfoTip from '../components/InfoTip'
import { Trophy } from 'lucide-react'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { usePersistentState } from '../lib/storage'
import { formatMoney, formatMoneyM } from '../utils/format'

const TABS = [
  { key: 'points', label: 'Per punts' },
  { key: 'economic', label: 'Econòmica' },
]

function TeamName({ row }) {
  const content = (
    <>
      <span className="block truncate font-semibold">{row.team}</span>
      {row.manager && row.manager !== row.team && <span className="block truncate text-xs text-muted-foreground">{row.manager}</span>}
    </>
  )
  if (!row.teamId) return <div className="min-w-0">{content}</div>
  return (
    <Link to={row.isMine ? '/team' : `/standings/${row.teamId}`} className="block min-w-0 hover:text-primary">
      {content}
    </Link>
  )
}

function Rank({ value }) {
  const medal = { 1: 'bg-warn/20 text-warn', 2: 'bg-muted-foreground/20 text-foreground', 3: 'bg-bear/15 text-bear' }[value]
  return (
    <span className={`num inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold ${medal || 'text-muted-foreground'}`}>
      {value}
    </span>
  )
}

/** Places gained (↑) or lost (↓) since the previous LaLiga update. */
function PositionMove({ row }) {
  if (row.previousPosition == null || row.previousPosition === row.position) return <span className="text-xs text-muted-foreground">—</span>
  const up = row.previousPosition > row.position
  return (
    <span className={`num text-xs font-semibold ${up ? 'text-bull' : 'text-bear'}`}>
      {up ? '↑' : '↓'} {Math.abs(row.previousPosition - row.position)}
    </span>
  )
}

function PointsTable({ rows }) {
  return (
    <div className="panel mt-4 overflow-hidden">
      <div className="grid grid-cols-[2.5rem_1fr_auto_4rem] items-center gap-3 border-b border-border bg-surface-raised/40 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        <span>#</span>
        <span>Equip</span>
        <span className="text-right">Punts</span>
        <span className="text-right">Canvi</span>
      </div>
      <div className="stagger divide-y divide-border">
        {rows.map((row) => (
          <div
            key={row.teamId ?? row.position}
            className={`grid grid-cols-[2.5rem_1fr_auto_4rem] items-center gap-3 px-4 py-3 ${row.isMine ? 'bg-primary/8' : 'hover:bg-surface-raised/50'}`}
          >
            <Rank value={row.position} />
            <TeamName row={row} />
            <span className="num text-right text-base font-bold">{row.points}</span>
            <span className="text-right">
              <PositionMove row={row} />
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}

function EconomicTable({ rows }) {
  const ranked = useMemo(() => [...rows].sort((a, b) => (b.teamValue ?? -1) - (a.teamValue ?? -1)), [rows])
  const mine = rows.find((r) => r.isMine)

  return (
    <>
      {mine && (
        <div className="stagger mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="panel p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">El teu valor d'equip</p>
            <p className="num mt-1 text-lg font-bold">{formatMoney(mine.teamValue)}</p>
          </div>
          <div className="panel p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">El teu pressupost</p>
            <p className="num mt-1 text-lg font-bold">{formatMoney(mine.cash)}</p>
          </div>
          <div className="panel col-span-2 p-4 sm:col-span-1">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Total (valor + pressupost)</p>
            <p className="num mt-1 text-lg font-bold text-primary">{formatMoney(mine.totalValue)}</p>
          </div>
        </div>
      )}

      <div className="panel mt-4 overflow-x-auto">
        <table className="w-full min-w-160 text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
              <th className="w-12 px-4 py-3">#</th>
              <th className="px-4 py-3">Equip</th>
              <th className="px-4 py-3 text-right">Valor d'equip</th>
              <th className="px-4 py-3 text-right">
                <span className="inline-flex items-center gap-1">
                  24h
                  <InfoTip>Quant ha pujat o baixat el valor dels jugadors que té ara mateix cada equip en les últimes 24 hores.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3 text-right">
                <span className="inline-flex items-center gap-1">
                  Pressupost
                  <InfoTip>LaLiga Fantasy només fa públic el saldo del teu equip; el dels rivals no es pot saber.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3 text-right">Total</th>
            </tr>
          </thead>
          <tbody className="stagger-rows">
            {ranked.map((row, i) => (
              <tr key={row.teamId ?? i} className={`border-b border-border last:border-0 ${row.isMine ? 'bg-primary/8' : 'hover:bg-surface-raised/50'}`}>
                <td className="px-4 py-3">
                  <Rank value={i + 1} />
                </td>
                <td className="max-w-56 px-4 py-3">
                  <TeamName row={row} />
                </td>
                <td className="num px-4 py-3 text-right font-semibold">{formatMoneyM(row.teamValue)}</td>
                <td className="px-4 py-3 text-right">
                  <ChangeBadge change={row.valueChange24h} changePct={row.valueChange24hPct} />
                </td>
                <td className="num px-4 py-3 text-right">
                  {row.cash != null ? formatMoneyM(row.cash) : <span className="text-xs text-muted-foreground">No públic</span>}
                </td>
                <td className="num px-4 py-3 text-right font-semibold">
                  {row.totalValue != null ? formatMoneyM(row.totalValue) : <span className="text-xs font-normal text-muted-foreground">—</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-muted-foreground">
        Ordenat per valor d'equip, l'única xifra econòmica que LaLiga publica de tots els equips. El total amb pressupost només es pot calcular per al
        teu equip.
      </p>
    </>
  )
}

export default function Standings() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [tab, setTab] = usePersistentState('standings-tab', 'points')

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

  const rows = data.data || []

  return (
    <div>
      <h1 className="text-3xl font-bold tracking-tight">Classificació</h1>
      {data.message && <p className="mt-2 text-sm text-muted-foreground">{data.message}</p>}

      <div className="mt-4 inline-flex gap-1 rounded-full border border-border bg-surface p-1" role="tablist">
        {TABS.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={tab === t.key}
            onClick={() => setTab(t.key)}
            className={`min-h-9 rounded-full px-4 text-sm font-semibold ${tab === t.key ? 'bg-primary text-background' : 'text-muted-foreground hover:text-foreground'}`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {rows.length === 0 ? (
        <EmptyState icon={Trophy} className="mt-4" title="Encara no hi ha classificació" message="Apareixerà després de la propera sincronització." />
      ) : (
        <div key={tab}>{tab === 'economic' ? <EconomicTable rows={rows} /> : <PointsTable rows={rows} />}</div>
      )}
    </div>
  )
}
