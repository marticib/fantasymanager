import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import ScoreRing from '../components/ScoreRing'
import Sparkline from '../components/Sparkline'
import { ShopIcon, ArrowRightIcon, ChevronDownIcon } from '../components/Icons'
import { formatMoneyM, formatDelta, formatPercent, formatCountdown, POSITION_LABELS, initials } from '../utils/format'

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
      className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
        active ? 'border-primary bg-primary/15 text-primary' : 'border-border text-muted-foreground hover:border-primary/50'
      }`}
    >
      {children}
    </button>
  )
}

function BuyStat({ label, value, colorClass }) {
  return (
    <div className="text-center">
      <p className={`text-lg font-bold ${colorClass || ''}`}>{value}</p>
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
    </div>
  )
}

function MarketRow({ row }) {
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
              <span
                className={eurDelta > 0 ? 'text-bull' : eurDelta < 0 ? 'text-bear' : 'text-muted-foreground'}
                title={own == null ? 'Font externa (futbolfantasy.com, no oficial) — encara no tenim prou historial propi' : undefined}
              >
                {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
                {own == null && <span className="text-[9px] text-muted-foreground"> *ext.</span>}
              </span>
            )
          })()}
        </td>
        <td className="px-4 py-3">
          <Link to={`/players/${row.player.id}`} className="flex items-center gap-2.5 hover:text-primary">
            <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
              {row.player.imageUrl ? (
                <img src={row.player.imageUrl} alt="" className="h-full w-full object-cover" />
              ) : (
                initials(row.player.name)
              )}
            </div>
            <div className="min-w-0">
              <p className="truncate font-medium">{row.player.name}</p>
              <p className="truncate text-xs text-muted-foreground">{row.player.clubShort || row.player.club || '—'}</p>
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
        <td
          className="w-24 px-4 py-3"
          title={
            row.historySource === 'external'
              ? "Línia reconstruïda amb dades de futbolfantasy.com (no oficial) — encara no tenim prou historial propi"
              : undefined
          }
        >
          <Sparkline data={row.history} />
          {row.historySource === 'external' && <span className="text-[9px] text-muted-foreground">*ext.</span>}
        </td>
        <td
          className={`px-4 py-3 ${
            row.externalTrend?.pct7d > 0 ? 'text-bull' : row.externalTrend?.pct7d < 0 ? 'text-bear' : 'text-muted-foreground'
          }`}
          title={row.externalTrend ? 'Font: futbolfantasy.com (no oficial)' : 'Sense dada externa'}
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
              <span
                className={`inline-flex cursor-help rounded-md px-2 py-1 text-xs font-bold ${ECONOMIC_BADGE[b.recommendation]}`}
                title="Anàlisi purament econòmica: si compro aquest jugador a aquest preu, és rentable? (ignora rendiment esportiu, titularitat i necessitat de plantilla)"
              >
                {ECONOMIC_LABEL[b.recommendation]}
              </span>
              <button
                onClick={() => setOpen((o) => !o)}
                className="mt-0.5 shrink-0 rounded p-0.5 text-muted-foreground hover:text-primary"
                title="Veure el càlcul econòmic"
              >
                <ChevronDownIcon className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
              </button>
            </div>
          )}
        </td>
      </tr>
      {open && b && (
        <tr className="border-b border-border bg-background/40 last:border-0">
          <td colSpan={11} className="px-4 py-4">
            <BuyAnalysisDetail b={b} />
          </td>
        </tr>
      )}
    </>
  )
}

function BuyAnalysisDetail({ b }) {
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Buy Economic Score — {b.classification}
        </p>
        <p className="mt-1.5 text-sm text-foreground">
          Preu d'adquisició {formatMoneyM(b.acquisitionPrice)} · valor projectat a 14 dies{' '}
          {formatMoneyM(b.projectedValue14d)} · ROI 14d {formatPercent(b.expectedROI14d * 100)}.{' '}
          {b.breakEvenDays === 0
            ? 'Ja recupera la inversió avui mateix (preu igual o per sota del valor de mercat).'
            : b.breakEvenDays !== null
              ? `Recupera la inversió en ${b.breakEvenDays} dies.`
              : 'No es preveu recuperar la inversió dins del termini simulat.'}
        </p>
        <p className="mt-2 text-xs text-muted-foreground">
          Oferta guanyadora estimada {formatMoneyM(b.estimatedWinningBid)} (prima{' '}
          {formatPercent(b.expectedWinningPremium * 100)},{' '}
          {b.auctionHistorySource === 'league' ? "historial real d'aquesta lliga" : 'valor per defecte, encara sense historial'})
          {b.bidCount != null && ` · ${b.bidCount} ofertes en aquesta subhasta`} · dades {b.dataQuality}%.
        </p>
      </div>
      <div className="flex shrink-0 gap-5 border-t border-border pt-3 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-5">
        <BuyStat label="Score" value={b.buyEconomicScore} colorClass="text-primary" />
        <BuyStat label="Màxim" value={formatMoneyM(b.maxBid)} />
        <BuyStat
          label="Oferta"
          value={b.recommendedBid === 'DO_NOT_CHASE' ? 'No perseguir' : formatMoneyM(b.recommendedBid)}
          colorClass={b.recommendedBid === 'DO_NOT_CHASE' ? 'text-bear' : 'text-bull'}
        />
      </div>
    </div>
  )
}

function MarketCard({ row }) {
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
          </div>
        </div>
        <span className={`num shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${scoreBadgeClass(row.fantasyScore)}`}>
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
              {!isOwn && <span className="text-[9px] opacity-80"> *ext.</span>}
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

      {open && b && (
        <div className="mt-3 border-t border-border pt-3">
          <BuyAnalysisDetail b={b} />
        </div>
      )}
    </div>
  )
}

export default function Market() {
  const [rows, setRows] = useState(null)
  const [meta, setMeta] = useState(null)
  const [error, setError] = useState('')

  const [search, setSearch] = useState('')
  const [position, setPosition] = useState(null)
  const [maxPriceM, setMaxPriceM] = useState(20)
  const [minScore, setMinScore] = useState(0)
  const [trend, setTrend] = useState(null)
  const [freeAgentOnly, setFreeAgentOnly] = useState(true)
  const [sort, setSort] = useState({ key: 'fantasyScore', direction: 'desc' })

  useEffect(() => {
    apiClient
      .get('/market')
      .then((res) => {
        setRows(res.data.data)
        setMeta(res.data.meta)
      })
      .catch((err) => setError(err.response?.data?.message || 'Error carregant el mercat.'))
  }, [])

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

  if (error) return <p className="text-bear">{error}</p>
  if (!rows) return <p className="text-muted-foreground">Carregant…</p>

  const countdown = meta?.closesAt ? formatCountdown(meta.closesAt) : null

  return (
    <div>
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
            <span className="inline-flex items-center gap-1.5 rounded-full bg-bull/15 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-bull">
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
                  <span
                    className="rounded-md bg-bull/15 px-2 py-1 text-sm font-semibold text-bull"
                    title="Font externa (futbolfantasy.com, no oficial) — encara no tenim prou historial propi"
                  >
                    {top.externalTrend.pct7d >= 0 ? '↗' : '↘'} {formatPercent(top.externalTrend.pct7d)} 7d*
                  </span>
                )
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
              <p className="text-[11px] font-semibold uppercase tracking-wide text-bull/80">Oferta recomanada</p>
              <p className="num mt-1 text-xl font-bold text-bull">{formatMoneyM(top.recommendedBid)}</p>
            </div>
            <div className="rounded-xl border border-border p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Màxim</p>
              <p className="num mt-1 text-xl font-bold">{formatMoneyM(top.maxBid)}</p>
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

      <div className="mt-6 rounded-2xl border border-border bg-surface p-4">
        <input
          placeholder="Buscar jugador…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full rounded-lg border border-border bg-background px-4 py-2.5 text-sm outline-none focus:border-primary"
        />

        <div className="mt-4 flex flex-wrap items-end gap-x-8 gap-y-4">
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Posició</p>
            <div className="flex gap-2">
              {POSITIONS.map((p) => (
                <Pill key={p} active={position === p} onClick={() => setPosition(position === p ? null : p)}>
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
              onChange={(e) => setMaxPriceM(Number(e.target.value))}
              className="w-full accent-primary"
            />
          </div>

          <div className="min-w-40">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fantasy Score · {minScore}+</p>
            <input
              type="range"
              min={0}
              max={100}
              step={5}
              value={minScore}
              onChange={(e) => setMinScore(Number(e.target.value))}
              className="w-full accent-primary"
            />
          </div>

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Tendència</p>
            <div className="flex gap-2">
              {TREND_OPTIONS.map((o) => (
                <Pill key={o.key} active={trend === o.key} onClick={() => setTrend(trend === o.key ? null : o.key)}>
                  {o.label}
                </Pill>
              ))}
            </div>
          </div>

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Propietari</p>
            <Pill active={freeAgentOnly} onClick={() => setFreeAgentOnly((v) => !v)}>
              Només mercat lliure
            </Pill>
          </div>
        </div>
      </div>

      <div className="mt-4 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-220 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('change24h')}>
                24H{sortArrow('change24h')}
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
                title="Font externa (futbolfantasy.com, no oficial)"
              >
                7d ext.*{sortArrow('externalPct7d')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('averagePoints')}>
                Mitjana{sortArrow('averagePoints')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('fantasyScore')}>
                Score{sortArrow('fantasyScore')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('recommendedBid')}>
                Oferta{sortArrow('recommendedBid')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('buyEconomicScore')}
                title="Anàlisi purament econòmica: si compro aquest jugador a aquest preu, és rentable? (desplega per veure el càlcul)"
              >
                Econòmic{sortArrow('buyEconomicScore')}
              </th>
            </tr>
          </thead>
          <tbody>
            {sorted.map((row) => (
              <MarketRow key={row.id} row={row} />
            ))}
          </tbody>
        </table>
        {sorted.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">Cap jugador amb aquests filtres.</p>}
      </div>

      <div className="mt-4 space-y-3 md:hidden">
        {sorted.map((row) => (
          <MarketCard key={row.id} row={row} />
        ))}
        {sorted.length === 0 && <p className="panel p-6 text-center text-sm text-muted-foreground">Cap jugador amb aquests filtres.</p>}
      </div>
    </div>
  )
}
