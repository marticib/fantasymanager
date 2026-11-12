import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { cachedGet, updateCached } from '../api/cachedGet'
import ScoreRing from '../components/ScoreRing'
import Sparkline from '../components/Sparkline'
import ActionModal from '../components/ActionModal'
import BidDialog from '../components/BidDialog'
import InfoTip from '../components/InfoTip'
import { PageSkeleton, EmptyState, ErrorState } from '../components/PageState'
import { ShopIcon, ArrowRightIcon, ChevronDownIcon } from '../components/Icons'
import { SearchX } from 'lucide-react'
import { usePersistentState } from '../lib/storage'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { formatMoney, formatMoneyM, formatDelta, formatPercent, formatCountdown, POSITION_LABELS, initials } from '../utils/format'

const POSITIONS = ['GK', 'DF', 'MF', 'FW']
const TREND_OPTIONS = [
  { key: 'RISING', label: 'Alcista' },
  { key: 'STABLE', label: 'Estable' },
  { key: 'FALLING', label: 'Baixista' },
]

const ECONOMIC_BADGE = {
  BUY: 'bg-bull/15 text-bull',
  CONSIDER: 'bg-primary/15 text-primary',
  WAIT: 'bg-warn/15 text-warn',
  DO_NOT_BUY: 'bg-bear/15 text-bear',
}

const ECONOMIC_LABEL = { BUY: 'COMPRAR', CONSIDER: 'CONSIDERAR', WAIT: 'ESPERAR', DO_NOT_BUY: 'NO COMPRAR' }

const DEFAULT_FILTERS = { position: null, maxPriceM: 20, minScore: 0, trend: null, freeAgentOnly: true }

const EXT_SOURCE_HELP = 'Dada de futbolfantasy.com (font externa, no oficial). Es fa servir mentre encara no tenim prou historial propi del jugador.'

/** Bid presets for a listing: current value, the recommended bid (unless it's not worth chasing) and the maximum. */
function bidPresets(row, overrides = {}) {
  const b = row.buyAnalysis
  const recommended = overrides.recommended ?? (b ? (b.recommendedBid === 'DO_NOT_CHASE' ? null : b.recommendedBid) : row.recommendedBid)
  return [
    { key: 'value', label: 'Valor', amount: row.marketValue },
    { key: 'recommended', label: 'Recomanada', amount: typeof recommended === 'number' ? recommended : null },
    { key: 'max', label: 'Màxim', amount: overrides.max ?? b?.maxBid ?? row.maxBid ?? null },
  ]
}

const SORT_ACCESSORS = {
  player: (r) => r.player.name,
  position: (r) => r.player.position,
  owner: (r) => r.sellerTeam || '',
  marketValue: (r) => r.marketValue,
  change24h: (r) => r.trend?.change24h ?? r.externalTrend?.delta1d,
  pctChange7d: (r) => r.trend?.pctChange7d,
  externalPct7d: (r) => r.externalTrend?.pct7d,
  averagePoints: (r) => r.player.averagePoints,
  fantasyScore: (r) => r.fantasyScore,
  recommendedBid: (r) => r.recommendedBid,
  buyEconomicScore: (r) => r.buyAnalysis?.buyEconomicScore,
}

function scoreBadgeClass(score) {
  if (score >= 80) return 'bg-bull/15 text-bull'
  if (score >= 65) return 'bg-primary/15 text-primary'
  return 'bg-warn/15 text-warn'
}

function trendBucket(classification) {
  if (classification === 'ALCISTA' || classification === 'MOLT_ALCISTA') return 'RISING'
  if (classification === 'BAIXISTA' || classification === 'MOLT_BAIXISTA') return 'FALLING'
  return 'STABLE'
}

function Pill({ active, onClick, children }) {
  return (
    <button
      onClick={onClick}
      className={`min-h-9 rounded-full border px-3.5 py-1.5 text-sm font-medium ${
        active ? 'border-primary bg-primary/15 text-primary' : 'border-border text-muted-foreground hover:border-primary/50'
      }`}
    >
      {children}
    </button>
  )
}

function BuyStat({ label, value, colorClass, onBid, busy }) {
  return (
    <div className="text-center">
      <p className={`text-lg font-bold ${colorClass || ''}`}>{value}</p>
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      {onBid && (
        <button
          onClick={onBid}
          disabled={busy}
          className="mt-1.5 min-h-9 rounded-md border border-border px-3 py-1 text-xs font-semibold text-muted-foreground hover:border-bull hover:text-bull disabled:opacity-50"
        >
          Pujar
        </button>
      )}
    </div>
  )
}

function MarketRow({ row, onBid, onCancelBid }) {
  const [open, setOpen] = useState(false)
  const b = row.buyAnalysis

  return (
    <>
      <tr className={`border-b border-border last:border-0 hover:bg-surface-raised ${row.action === 'BUY' ? 'bg-bull/5' : ''}`}>
        <td className="px-4 py-3">
          {(() => {
            const own = row.trend?.change24h
            const eurDelta = own ?? row.externalTrend?.delta1d
            if (eurDelta == null) return <span className="text-muted-foreground">—</span>
            return (
              <span className={`whitespace-nowrap ${eurDelta > 0 ? 'text-bull' : eurDelta < 0 ? 'text-bear' : 'text-muted-foreground'}`}>
                {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
                {own == null && <span className="text-[11px] text-muted-foreground"> *ext.</span>}
              </span>
            )
          })()}
        </td>
        <td className="px-4 py-3">
          <Link to={`/players/${row.player.id}`} className="flex items-center gap-2.5 hover:text-primary">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[11px] font-bold text-muted-foreground">
              {row.player.imageUrl ? (
                <img src={row.player.imageUrl} alt="" className="h-full w-full object-cover" />
              ) : (
                initials(row.player.name)
              )}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium">{row.player.name}</p>
              <p className="truncate text-xs text-muted-foreground">{row.player.clubShort || row.player.club || '—'}</p>
              {row.myBid && (
                <p className="num truncate text-xs font-semibold text-primary">La teva oferta: {formatMoney(row.myBid.money)}</p>
              )}
            </div>
          </Link>
        </td>
        <td className="px-4 py-3">
          <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
            {POSITION_LABELS[row.player.position] || row.player.position}
          </span>
        </td>
        <td className="px-4 py-3 text-muted-foreground">{row.sellerTeam || <span className="italic">Mercat lliure</span>}</td>
        <td className="num px-4 py-3 font-medium">{formatMoneyM(row.marketValue)}</td>
        <td className="w-24 px-4 py-3">
          <Sparkline data={row.history} />
          {row.historySource === 'external' && <span className="text-[11px] text-muted-foreground">*ext.</span>}
        </td>
        <td
          className={`px-4 py-3 ${
            row.externalTrend?.pct7d > 0 ? 'text-bull' : row.externalTrend?.pct7d < 0 ? 'text-bear' : 'text-muted-foreground'
          }`}
        >
          {row.externalTrend?.pct7d != null
            ? `${row.externalTrend.pct7d >= 0 ? '↗' : '↘'} ${formatPercent(row.externalTrend.pct7d)}`
            : '—'}
        </td>
        <td className="px-4 py-3">{row.player.averagePoints?.toFixed(1)}</td>
        <td className="px-4 py-3">
          <span className={`rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(row.fantasyScore)}`}>
            {Math.round(row.fantasyScore)}
          </span>
        </td>
        <td className="num px-4 py-3">{formatMoneyM(row.recommendedBid)}</td>
        <td className="px-4 py-3">
          {b && (
            <div className="flex items-start gap-1">
              <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ECONOMIC_BADGE[b.recommendation]}`}>
                {ECONOMIC_LABEL[b.recommendation]}
              </span>
              <button
                onClick={() => setOpen((o) => !o)}
                className="shrink-0 rounded p-1 text-muted-foreground hover:text-primary"
                aria-label={open ? 'Amagar el càlcul' : 'Veure el càlcul'}
                aria-expanded={open}
              >
                <ChevronDownIcon className={`h-4 w-4 transition-transform ${open ? 'rotate-180' : ''}`} />
              </button>
            </div>
          )}
        </td>
        <td className="px-4 py-3 text-right">
          {row.myBid ? (
            <button
              onClick={() => onCancelBid(row)}
              className="min-h-9 rounded-md border border-border px-3 text-xs font-semibold text-muted-foreground hover:border-bear hover:text-bear"
            >
              Cancel·lar
            </button>
          ) : (
            <button
              onClick={() => onBid(row)}
              className="min-h-9 rounded-md bg-bull/15 px-3 text-xs font-semibold text-bull hover:bg-bull/25"
            >
              Pujar
            </button>
          )}
        </td>
      </tr>
      {open && b && (
        <tr className="animate-fade border-b border-border bg-background/40 last:border-0">
          <td colSpan={12} className="px-4 py-4">
            <BuyAnalysisDetail row={row} b={b} onBid={onBid} onCancelBid={onCancelBid} />
          </td>
        </tr>
      )}
    </>
  )
}

function BuyAnalysisDetail({ row, b, onBid, onCancelBid }) {
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Valoració econòmica — {b.classification}
        </p>
        <p className="mt-1.5 text-sm text-foreground">
          Preu de compra estimat {formatMoneyM(b.acquisitionPrice)} · valor previst d'aquí a 14 dies{' '}
          {formatMoneyM(b.projectedValue14d)} · rendiment en 14 dies {formatPercent(b.expectedROI14d * 100)}.{' '}
          {b.breakEvenDays === 0
            ? 'Ja recupera la inversió avui mateix (preu igual o per sota del valor de mercat).'
            : b.breakEvenDays !== null
              ? `Recupera la inversió en ${b.breakEvenDays} dies.`
              : 'No es preveu recuperar la inversió dins del termini simulat.'}
        </p>
        <p className="mt-2 text-xs text-muted-foreground">
          Oferta guanyadora estimada {formatMoneyM(b.estimatedWinningBid)} (sobrecost sobre el valor{' '}
          {formatPercent(b.expectedWinningPremium * 100)},{' '}
          {b.auctionHistorySource === 'league' ? "historial real d'aquesta lliga" : 'valor per defecte, encara sense historial'})
          {b.bidCount != null && ` · ${b.bidCount} ofertes en aquesta subhasta`} · fiabilitat de les dades {b.dataQuality}%.
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap gap-5 border-t border-border pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
        <BuyStat label="Valoració" value={b.buyEconomicScore} colorClass="text-primary" />
        <BuyStat label="Valor" value={formatMoneyM(row.marketValue)} onBid={onBid && (() => onBid(row, 'value'))} />
        <BuyStat label="Màxim" value={formatMoneyM(b.maxBid)} onBid={onBid && (() => onBid(row, 'max'))} />
        <BuyStat
          label="Oferta"
          value={b.recommendedBid === 'DO_NOT_CHASE' ? 'No val la pena pujar més' : formatMoneyM(b.recommendedBid)}
          colorClass={b.recommendedBid === 'DO_NOT_CHASE' ? 'text-bear text-sm' : 'text-bull'}
          onBid={onBid && b.recommendedBid !== 'DO_NOT_CHASE' && (() => onBid(row, 'recommended'))}
        />
        {row.myBid && (
          <div className="text-center">
            <p className="num text-lg font-bold text-primary">{formatMoney(row.myBid.money)}</p>
            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">La teva oferta</p>
            <button
              onClick={() => onCancelBid(row)}
              className="mt-1.5 min-h-9 rounded-md border border-border px-3 py-1 text-xs font-semibold text-muted-foreground hover:border-bear hover:text-bear"
            >
              Cancel·lar
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

function MarketCard({ row, onBid, onCancelBid }) {
  const [open, setOpen] = useState(false)
  const b = row.buyAnalysis
  const own = row.trend?.change24h
  const eurDelta = own ?? row.externalTrend?.delta1d
  const isOwn = own != null

  return (
    <div className="panel p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-border-strong">
      <button onClick={() => setOpen((o) => !o)} className="flex w-full items-start justify-between gap-3 text-left">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
            {row.player.imageUrl ? <img src={row.player.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.player.name)}
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold">{row.player.name}</p>
            <p className="truncate text-xs text-muted-foreground">
              {row.player.clubShort || row.player.club || '—'} · {POSITION_LABELS[row.player.position] || row.player.position}
            </p>
            {row.myBid && (
              <p className="num truncate text-xs font-semibold text-primary">La teva oferta: {formatMoney(row.myBid.money)}</p>
            )}
          </div>
        </div>
        <span className={`num shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${scoreBadgeClass(row.fantasyScore)}`} aria-label="Puntuació">
          {Math.round(row.fantasyScore)}
        </span>
      </button>

      <div className="mt-3 flex items-end justify-between gap-3">
        <div>
          <p className="num text-2xl font-semibold">{formatMoneyM(row.marketValue)}</p>
          {eurDelta == null ? (
            <span className="mt-1.5 inline-flex text-xs text-muted-foreground">—</span>
          ) : (
            <span
              className={`num mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                eurDelta > 0 ? 'bg-bull-soft text-bull' : eurDelta < 0 ? 'bg-bear-soft text-bear' : 'bg-surface-raised text-muted-foreground'
              }`}
            >
              {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
              {!isOwn && <span className="text-[11px] opacity-80"> *ext.</span>}
            </span>
          )}
        </div>
        {row.history?.length > 1 && (
          <div className="h-8 w-24 shrink-0">
            <Sparkline data={row.history} />
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
        <span>{row.sellerTeam || 'Mercat lliure'}</span>
        <span className="num">mitjana {row.player.averagePoints?.toFixed(1)}</span>
      </div>

      {b && (
        <div className="mt-3 flex items-center justify-between gap-2">
          <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ECONOMIC_BADGE[b.recommendation]}`}>
            {ECONOMIC_LABEL[b.recommendation]}
          </span>
          <span className="num text-sm font-semibold">{formatMoneyM(row.recommendedBid)}</span>
        </div>
      )}

      <div className="mt-3 flex gap-2">
        {row.myBid ? (
          <button
            onClick={() => onCancelBid(row)}
            className="min-h-11 flex-1 rounded-lg border border-border text-sm font-semibold text-muted-foreground hover:border-bear hover:text-bear"
          >
            Cancel·lar oferta
          </button>
        ) : (
          <button onClick={() => onBid(row)} className="min-h-11 flex-1 rounded-lg bg-bull/15 text-sm font-semibold text-bull hover:bg-bull/25">
            Pujar
          </button>
        )}
        {b && (
          <button
            onClick={() => setOpen((o) => !o)}
            className="min-h-11 rounded-lg border border-border px-3 text-sm font-semibold text-muted-foreground hover:text-foreground"
            aria-expanded={open}
          >
            {open ? 'Amagar càlcul' : 'Veure càlcul'}
          </button>
        )}
      </div>

      {open && b && (
        <div className="mt-3 animate-enter border-t border-border pt-3">
          <BuyAnalysisDetail row={row} b={b} onBid={onBid} onCancelBid={onCancelBid} />
        </div>
      )}
    </div>
  )
}

export default function Market() {
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState(null)
  const [error, setError] = useState('')

  // Filters and sort survive leaving the page and coming back; the search box doesn't.
  const [search, setSearch] = useState('')
  const [filters, setFilters] = usePersistentState('market-filters', DEFAULT_FILTERS)
  const [sort, setSort] = usePersistentState('market-sort', { key: 'fantasyScore', direction: 'desc' })
  const { position, maxPriceM, minScore, trend, freeAgentOnly } = { ...DEFAULT_FILTERS, ...filters }
  const setFilter = (key, value) => setFilters((f) => ({ ...DEFAULT_FILTERS, ...f, [key]: value }))
  const filtersActive = search !== '' || JSON.stringify({ ...DEFAULT_FILTERS, ...filters }) !== JSON.stringify(DEFAULT_FILTERS)
  const clearFilters = () => {
    setSearch('')
    setFilters(DEFAULT_FILTERS)
  }

  const [pendingAction, setPendingAction] = useState(null)
  const [pendingBid, setPendingBid] = useState(null)

  const load = () => {
    setError('')
    cachedGet('/market', null, (d) => {
      setRows(d.data)
      setMeta(d.meta)
    }).catch((err) => setError(err.response?.data?.message || "No s'ha pogut carregar el mercat."))
  }

  useEffect(load, [])
  useAppEvent(DATA_CHANGED, load)

  // Update the list the instant a bid/cancel succeeds rather than waiting on
  // the next market sync (minutes away) — otherwise it seems to do nothing.
  const patchRow = (id, changes) => {
    const patch = (r) => (r.id === id ? { ...r, ...changes } : r)
    setRows((prev) => prev.map(patch))
    updateCached('/market', null, (d) => ({ ...d, data: d.data.map(patch) }))
  }

  const askBid = (row, initialKey = 'recommended', overrides) => {
    setPendingBid({
      playerName: row.player.name,
      marketPlayerId: row.id,
      presets: bidPresets(row, overrides),
      initialKey,
      onPlaced: (myBid) => patchRow(row.id, { myBid }),
    })
  }

  const askCancelBid = (row) => {
    setPendingAction({
      title: 'Cancel·lar oferta',
      description: (
        <>
          Retirar la teva oferta de <span className="num font-semibold text-foreground">{formatMoney(row.myBid.money)}</span> per{' '}
          <span className="font-semibold text-foreground">{row.player.name}</span>?
        </>
      ),
      confirmLabel: 'Sí, cancel·lar',
      tone: 'danger',
      run: async () => {
        await apiClient.delete(`/market/${row.id}/bid`)
        patchRow(row.id, { myBid: null })
        return `Oferta cancel·lada per ${row.player.name}.`
      },
    })
  }

  const handleModalClose = () => {
    setPendingAction(null)
  }

  const myBids = useMemo(() => (rows || []).filter((r) => r.myBid), [rows])

  const filtered = useMemo(() => {
    if (!rows) return []
    return rows.filter((r) => {
      if (search && !r.player.name.toLowerCase().includes(search.toLowerCase())) return false
      if (position && r.player.position !== position) return false
      if (maxPriceM < 20 && r.marketValue > maxPriceM * 1_000_000) return false
      if (r.fantasyScore < minScore) return false
      if (trend && trendBucket(r.effectiveClassification) !== trend) return false
      if (freeAgentOnly && r.sellerTeam) return false
      return true
    })
  }, [rows, search, position, maxPriceM, minScore, trend, freeAgentOnly])

  const sorted = useMemo(() => {
    const dir = sort.direction === 'asc' ? 1 : -1
    const accessor = SORT_ACCESSORS[sort.key] || SORT_ACCESSORS.fantasyScore
    return [...filtered].sort((a, b) => {
      const av = accessor(a)
      const bv = accessor(b)
      if (av === null || av === undefined || av === '') return 1
      if (bv === null || bv === undefined || bv === '') return -1
      if (typeof av === 'string') return av.localeCompare(bv) * dir
      return av > bv ? dir : av < bv ? -dir : 0
    })
  }, [filtered, sort])

  const toggleSort = (key) => {
    setSort((s) => (s.key === key ? { key, direction: s.direction === 'desc' ? 'asc' : 'desc' } : { key, direction: 'desc' }))
  }

  const sortArrow = (key) => (sort.key === key ? (sort.direction === 'desc' ? ' ↓' : ' ↑') : '')

  const top = useMemo(() => sorted.find((r) => r.action === 'BUY') || null, [sorted])

  if (error && !rows) return <ErrorState message={error} onRetry={load} />
  if (!rows) return <PageSkeleton cards={0} rows={8} />

  const countdown = meta?.closesAt ? formatCountdown(meta.closesAt) : null

  return (
    <div>
      <ActionModal request={pendingAction} onClose={handleModalClose} />
      <BidDialog bid={pendingBid} onClose={() => setPendingBid(null)} />

      {myBids.length > 0 && (
        <div className="mb-5 rounded-2xl border border-primary/30 bg-primary/5 p-4">
          <p className="text-sm font-semibold">
            Les teves ofertes actives ({myBids.length})
          </p>
          <div className="mt-3 space-y-2">
            {myBids.map((row) => (
              <div key={row.id} className="flex items-center justify-between gap-3 rounded-lg border border-border bg-surface px-3 py-2">
                <Link to={`/players/${row.player.id}`} className="min-w-0 flex-1 truncate text-sm font-medium hover:text-primary">
                  {row.player.name}
                </Link>
                <span className="num shrink-0 text-sm font-semibold text-primary">{formatMoney(row.myBid.money)}</span>
                <button
                  onClick={() => askCancelBid(row)}
                  className="min-h-9 shrink-0 rounded-md border border-border px-3 py-1 text-xs font-semibold text-muted-foreground hover:border-bear hover:text-bear"
                >
                  Cancel·lar
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Mercat</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {meta?.totalAvailable ?? rows.length} jugadors disponibles · {sorted.length} després dels filtres
          </p>
        </div>
        {countdown && (
          <span className="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm text-muted-foreground">
            <ShopIcon className="h-4 w-4" />
            Tanca en {countdown}
          </span>
        )}
      </div>

      {top && (
        <div className="mt-5 flex flex-col gap-6 rounded-2xl border border-bull/30 bg-bull/5 p-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex-1">
            <span className="inline-flex items-center gap-1.5 rounded-full bg-bull/15 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-bull">
              <span className="h-1.5 w-1.5 rounded-full bg-bull" />
              Top oportunitat
            </span>
            <p className="mt-3 text-2xl font-bold">{top.player.name}</p>
            <p className="text-sm text-muted-foreground">
              {top.player.club || 'Club desconegut'} · {POSITION_LABELS[top.player.position] || top.player.position}
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <p className="num text-3xl font-extrabold">{formatMoneyM(top.marketValue)}</p>
              {top.trend.pctChange7d !== null ? (
                <span className="rounded-md bg-bull/15 px-2 py-1 text-sm font-semibold text-bull">
                  ↗ {formatPercent(top.trend.pctChange7d)} 7d
                </span>
              ) : (
                top.externalTrend?.pct7d != null && (
                  <span className="rounded-md bg-bull/15 px-2 py-1 text-sm font-semibold text-bull">
                    {top.externalTrend.pct7d >= 0 ? '↗' : '↘'} {formatPercent(top.externalTrend.pct7d)} 7d*
                  </span>
                )
              )}
              {!top.myBid && (
                <button
                  onClick={() => askBid(top, 'value', { recommended: top.recommendedBid, max: top.maxBid })}
                  className="min-h-9 rounded-md border border-border px-3 py-1 text-xs font-semibold text-muted-foreground hover:border-bull hover:text-bull"
                >
                  Pujar al valor
                </button>
              )}
            </div>
            {!top.trend.pctChange7d && top.externalTrend?.pct7d != null && (
              <p className="mt-1 text-xs text-muted-foreground">*Font externa, no oficial — futbolfantasy.com</p>
            )}
            {top.history?.length > 1 && (
              <div className="mt-3 max-w-xs">
                <Sparkline data={top.history} height={48} />
              </div>
            )}
          </div>

          <ScoreRing score={top.fantasyScore} />

          <div className="grid grid-cols-2 gap-3 lg:w-72">
            <div className="rounded-xl bg-bull/10 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-bull/80">Oferta recomanada</p>
              <p className="num mt-1 text-xl font-bold text-bull">{formatMoneyM(top.recommendedBid)}</p>
              <button
                onClick={() => askBid(top, 'recommended', { recommended: top.recommendedBid, max: top.maxBid })}
                className="mt-2 min-h-9 w-full rounded-md bg-bull/20 py-1 text-xs font-semibold text-bull hover:bg-bull/30"
              >
                Pujar
              </button>
            </div>
            <div className="rounded-xl border border-border p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Màxim</p>
              <p className="num mt-1 text-xl font-bold">{formatMoneyM(top.maxBid)}</p>
              <button
                onClick={() => askBid(top, 'max', { recommended: top.recommendedBid, max: top.maxBid })}
                className="mt-2 min-h-9 w-full rounded-md border border-border py-1 text-xs font-semibold text-muted-foreground hover:border-bull hover:text-bull"
              >
                Pujar
              </button>
            </div>
            <Link
              to={`/players/${top.player.id}`}
              className="col-span-2 flex items-center justify-center gap-1.5 rounded-lg bg-bull/20 py-2.5 text-sm font-semibold text-bull hover:bg-bull/30"
            >
              Analitzar <ArrowRightIcon className="h-4 w-4" />
            </Link>
          </div>
        </div>
      )}

      <div className="mt-6 panel p-4">
        <input
          placeholder="Buscar jugador…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="min-h-11 w-full rounded-xl border border-border bg-background/60 px-4 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
        />

        <div className="mt-4 flex flex-wrap items-end gap-x-8 gap-y-4">
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Posició</p>
            <div className="flex gap-2">
              {POSITIONS.map((p) => (
                <Pill key={p} active={position === p} onClick={() => setFilter('position', position === p ? null : p)}>
                  {POSITION_LABELS[p]}
                </Pill>
              ))}
            </div>
          </div>

          <div className="min-w-45">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Preu · fins a {maxPriceM >= 20 ? '20M+' : `${maxPriceM}M`}
            </p>
            <input
              type="range"
              min={0}
              max={20}
              step={0.5}
              value={maxPriceM}
              onChange={(e) => setFilter('maxPriceM', Number(e.target.value))}
              className="w-full accent-primary"
            />
          </div>

          <div className="min-w-40">
            <p className="mb-2 flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Puntuació mínima · {minScore}+
              <InfoTip>Nota de 0 a 100 que combina rendiment esportiu, tendència del valor i encaix a la teva plantilla.</InfoTip>
            </p>
            <input
              type="range"
              min={0}
              max={100}
              step={5}
              value={minScore}
              onChange={(e) => setFilter('minScore', Number(e.target.value))}
              className="w-full accent-primary"
            />
          </div>

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tendència</p>
            <div className="flex gap-2">
              {TREND_OPTIONS.map((o) => (
                <Pill key={o.key} active={trend === o.key} onClick={() => setFilter('trend', trend === o.key ? null : o.key)}>
                  {o.label}
                </Pill>
              ))}
            </div>
          </div>

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Propietari</p>
            <Pill active={freeAgentOnly} onClick={() => setFilter('freeAgentOnly', !freeAgentOnly)}>
              Només mercat lliure
            </Pill>
          </div>
        </div>
        {filtersActive && (
          <button onClick={clearFilters} className="mt-4 text-sm font-semibold text-primary hover:underline">
            Treure filtres
          </button>
        )}
      </div>

      <div className="mt-4 hidden overflow-x-auto panel md:block">
        <table className="w-full min-w-240 text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('change24h')}>
                <span className="inline-flex items-center gap-1">
                  24H{sortArrow('change24h')}
                  <InfoTip>Quant ha canviat el valor de mercat en les últimes 24 hores. «*ext.» vol dir que la dada és externa. {EXT_SOURCE_HELP}</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('player')}>
                Jugador{sortArrow('player')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('position')}>
                Pos.{sortArrow('position')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('owner')}>
                Propietari{sortArrow('owner')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('marketValue')}>
                Valor{sortArrow('marketValue')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('pctChange7d')}>
                7d{sortArrow('pctChange7d')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('externalPct7d')}
              >
                <span className="inline-flex items-center gap-1">
                  7d ext.*{sortArrow('externalPct7d')}
                  <InfoTip>Variació del valor en 7 dies segons futbolfantasy.com (font externa, no oficial).</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('averagePoints')}>
                Mitjana{sortArrow('averagePoints')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('fantasyScore')}>
                <span className="inline-flex items-center gap-1">
                  Puntuació{sortArrow('fantasyScore')}
                  <InfoTip>Nota de 0 a 100 que combina rendiment esportiu, tendència del valor i encaix a la teva plantilla.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('recommendedBid')}>
                <span className="inline-flex items-center gap-1">
                  Oferta{sortArrow('recommendedBid')}
                  <InfoTip>L'import que et recomanem pujar per tenir opcions de guanyar la subhasta sense pagar de més.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('buyEconomicScore')}>
                <span className="inline-flex items-center gap-1">
                  Econòmic{sortArrow('buyEconomicScore')}
                  <InfoTip>
                    Només mira els diners: si el compres a aquest preu, és probable que guanyis valor? No té en compte si juga o si el necessites. Obre la
                    fletxa per veure el càlcul.
                  </InfoTip>
                </span>
              </th>
              <th className="px-4 py-3 text-right">Acció</th>
            </tr>
          </thead>
          <tbody className="stagger-rows">
            {sorted.map((row) => (
              <MarketRow key={row.id} row={row} onBid={askBid} onCancelBid={askCancelBid} />
            ))}
          </tbody>
        </table>
        {sorted.length === 0 && (
          <EmptyState
            icon={SearchX}
            className="m-4"
            title="Cap jugador amb aquests filtres"
            message="Prova d'ampliar el preu màxim o de treure algun filtre."
            actionLabel={filtersActive ? 'Treure filtres' : undefined}
            onAction={clearFilters}
          />
        )}
      </div>

      <div className="stagger mt-4 space-y-3 md:hidden">
        {sorted.map((row) => (
          <MarketCard key={row.id} row={row} onBid={askBid} onCancelBid={askCancelBid} />
        ))}
        {sorted.length === 0 && (
          <EmptyState
            icon={SearchX}
            title="Cap jugador amb aquests filtres"
            message="Prova d'ampliar el preu màxim o de treure algun filtre."
            actionLabel={filtersActive ? 'Treure filtres' : undefined}
            onAction={clearFilters}
          />
        )}
      </div>
    </div>
  )
}
