import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { cachedGet } from '../api/cachedGet'
import ScoreRing from '../components/ScoreRing'
import Sparkline from '../components/Sparkline'
import { ShieldIcon, WalletIcon, ArrowRightIcon } from '../components/Icons'
import ClauseOrdersPanel from '../components/ClauseOrdersPanel'
import InfoTip from '../components/InfoTip'
import { PageSkeleton, EmptyState, ErrorState } from '../components/PageState'
import { SearchX } from 'lucide-react'
import { usePersistentState } from '../lib/storage'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { formatMoney, formatMoneyM, formatDelta, formatPercent, formatCountdown, POSITION_LABELS, initials, ACTION_LABELS } from '../utils/format'
import apiClient from '../api/client'
import ActionModal from '../components/ActionModal'

const POSITIONS = ['GK', 'DF', 'MF', 'FW']
const DEFAULT_FILTERS = { position: null, onlyRecommended: false, onlyLocked: true }

const ACTION_BADGE = {
  PAY_CLAUSE: 'bg-info/15 text-info',
  CONSIDER: 'bg-bull/15 text-bull',
  WAIT: 'bg-warn/15 text-warn',
  DO_NOT_PAY: 'bg-bear/15 text-bear',
}

const CLASSIFICATION_LABELS = {
  EXCEPTIONAL: 'EXCEPCIONAL',
  VERY_GOOD: 'MOLT BONA',
  GOOD: 'BONA',
  NEUTRAL: 'NEUTRA',
  BAD: 'DOLENTA',
  VERY_BAD: 'MOLT DOLENTA',
}

const CLASSIFICATION_BADGE = {
  EXCEPTIONAL: 'bg-info/15 text-info',
  VERY_GOOD: 'bg-bull/15 text-bull',
  GOOD: 'bg-bull/10 text-bull',
  NEUTRAL: 'bg-muted-foreground/15 text-muted-foreground',
  BAD: 'bg-warn/15 text-warn',
  VERY_BAD: 'bg-bear/15 text-bear',
}

const CLASSIFICATION_RING_COLOR = {
  EXCEPTIONAL: 'var(--color-info)',
  VERY_GOOD: 'var(--color-bull)',
  GOOD: 'var(--color-bull)',
  NEUTRAL: 'var(--color-muted-foreground)',
  BAD: 'var(--color-warn)',
  VERY_BAD: 'var(--color-bear)',
}

const SORT_ACCESSORS = {
  player: (r) => r.playerName,
  position: (r) => r.position,
  owner: (r) => r.ownerTeamName || '',
  change24h: (r) => r.trend?.change24h ?? r.externalTrend?.delta1d,
  marketValue: (r) => r.marketValue,
  pctChange7d: (r) => r.trend?.pctChange7d ?? r.externalTrend?.pct7d,
  clauseValue: (r) => r.clauseValue,
  daysUntilUnlock: (r) => r.daysUntilUnlock ?? 0,
  premium: (r) => r.clausePremiumPct,
  roi14d: (r) => r.roi14d,
  breakEvenDays: (r) => (r.breakEvenDays === null ? Infinity : r.breakEvenDays),
  clauseEconomicScore: (r) => r.clauseEconomicScore,
  economicRecommendation: (r) => r.clauseEconomicScore,
}

function scoreBadgeClass(score) {
  if (score >= 75) return 'bg-bull/15 text-bull'
  if (score >= 40) return 'bg-muted-foreground/15 text-muted-foreground'
  return 'bg-bear/15 text-bear'
}

function premiumClass(pct) {
  if (pct === null || pct === undefined) return 'text-muted-foreground'
  if (pct <= 0) return 'text-bull'
  if (pct <= 0.2) return 'text-muted-foreground'
  return 'text-bear'
}

function changeClass(pct) {
  if (pct === null || pct === undefined) return 'text-muted-foreground'
  if (pct > 0) return 'text-bull'
  if (pct < 0) return 'text-bear'
  return 'text-muted-foreground'
}

function breakEvenLabel(days) {
  if (days === null || days === undefined) return 'Sense recuperació'
  if (days === 0) return 'Immediat'
  return `${days} ${days === 1 ? 'dia' : 'dies'}`
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

function ClauseCard({ row }) {
  const own = row.trend?.change24h
  const eurDelta = own ?? row.externalTrend?.delta1d

  return (
    <Link to={`/players/${row.playerId}`} className="panel block p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-border-strong">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
            {row.imageUrl ? <img src={row.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.playerName)}
          </div>
          <div className="min-w-0">
            <p className="truncate font-semibold">{row.playerName}</p>
            <p className="truncate text-xs text-muted-foreground">
              {row.club || '—'} · {POSITION_LABELS[row.position] || row.position}
            </p>
          </div>
        </div>
        <span className={`num shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${scoreBadgeClass(row.clauseEconomicScore)}`}>
          {row.clauseEconomicScore}
        </span>
      </div>

      <div className="mt-3 flex items-end justify-between gap-3">
        <div>
          <p className="num text-2xl font-semibold">
            {formatMoneyM(row.clauseValue)}
            {(row.isLocked || row.isShielded) && <ShieldIcon className="ml-1.5 inline h-3.5 w-3.5 text-muted-foreground" />}
          </p>
          <p className={`num mt-1 text-xs font-medium ${premiumClass(row.clausePremiumPct)}`}>
            {row.clausePremiumPct !== null ? formatPercent(row.clausePremiumPct * 100) + ' vs. valor' : '—'}
          </p>
        </div>
        {eurDelta != null && (
          <span
            className={`num inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
              eurDelta > 0 ? 'bg-bull-soft text-bull' : eurDelta < 0 ? 'bg-bear-soft text-bear' : 'bg-surface-raised text-muted-foreground'
            }`}
          >
            {eurDelta >= 0 ? '↗' : '↘'} {formatDelta(eurDelta)}
          </span>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
        <span>{row.ownerTeamName || '—'}</span>
        <span>{row.isLocked ? `Blindada ${row.daysUntilUnlock}d` : 'Desbloquejada'}</span>
      </div>

      <div className="mt-3 flex items-center justify-between gap-2">
        <span className={`inline-flex rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[row.economicRecommendation]}`}>
          {ACTION_LABELS[row.economicRecommendation]}
        </span>
        <span className="num text-sm text-muted-foreground">
          Rendiment 14d <span className={`font-semibold ${changeClass(row.roi14d)}`}>{row.roi14d !== null ? formatPercent(row.roi14d * 100) : '—'}</span>
        </span>
      </div>
    </Link>
  )
}

const RADAR_DAYS = 7

/**
 * Rival clauses that unlock within the week, soonest first — with a one-tap
 * automatic purchase order (paid the moment it unlocks, if still worth it).
 */
function ClauseRadar({ rows, onSchedule }) {
  const soon = rows
    .filter((r) => r.isLocked && !r.isShielded && r.clauseLockedUntil && new Date(r.clauseLockedUntil) - Date.now() < RADAR_DAYS * 864e5)
    .sort((a, b) => new Date(a.clauseLockedUntil) - new Date(b.clauseLockedUntil))

  if (soon.length === 0) return null

  const unlockLabel = (iso) =>
    new Intl.DateTimeFormat('ca-ES', { weekday: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso))

  return (
    <section className="panel mt-5 overflow-hidden">
      <div className="border-b border-border bg-surface-raised/40 px-4 py-3 sm:px-5">
        <h2 className="flex items-center gap-1.5 font-semibold">
          Es desbloquegen aviat <span className="text-sm font-normal text-muted-foreground">({soon.length})</span>
          <InfoTip>
            Clàusules de rivals que es podran pagar en els propers {RADAR_DAYS} dies. «Programar» crea una compra automàtica: l'app la paga quan es
            desbloquegi, i si el rival l'ha pujat abans et demana confirmació.
          </InfoTip>
        </h2>
      </div>
      <div className="stagger divide-y divide-border">
        {soon.map((r) => {
          const recommended = r.economicRecommendation === 'PAY_CLAUSE' || r.economicRecommendation === 'CONSIDER'
          return (
            <div key={`${r.playerId}-${r.ownerTeamId}`} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
              <Link to={`/players/${r.playerId}`} className="flex min-w-0 flex-1 items-center gap-3 hover:text-primary">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-raised text-xs font-bold text-muted-foreground">
                  {r.imageUrl ? <img src={r.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(r.playerName)}
                </span>
                <span className="min-w-0">
                  <span className="block truncate font-semibold">{r.playerName}</span>
                  <span className="block truncate text-xs text-muted-foreground">
                    {POSITION_LABELS[r.position] || r.position} · {r.ownerTeamName || '—'}
                  </span>
                </span>
              </Link>
              <div className="text-right">
                <p className="num text-sm font-semibold">{formatMoneyM(r.clauseValue)}</p>
                <p className="text-[11px] text-muted-foreground">clàusula</p>
              </div>
              <div className="w-32 text-right">
                <p className="text-sm font-semibold text-info">d'aquí {formatCountdown(r.clauseLockedUntil) || 'poc'}</p>
                <p className="text-[11px] capitalize text-muted-foreground">{unlockLabel(r.clauseLockedUntil)}</p>
              </div>
              <span className={`rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[r.economicRecommendation]}`}>{ACTION_LABELS[r.economicRecommendation]}</span>
              <button
                onClick={() => onSchedule(r)}
                className={`min-h-9 rounded-lg px-3 text-xs font-semibold ${
                  recommended ? 'bg-info text-background hover:brightness-110' : 'border border-border text-muted-foreground hover:text-foreground'
                }`}
              >
                Programar
              </button>
            </div>
          )
        })}
      </div>
    </section>
  )
}

export default function Clauses() {
  const [payload, setPayload] = useState(null)
  const [pendingAction, setPendingAction] = useState(null)
  const [ordersKey, setOrdersKey] = useState(0)

  const scheduleOrder = (row) =>
    setPendingAction({
      title: `Programar la compra de ${row.playerName}`,
      description: (
        <>
          Quan la clàusula es desbloquegi, l'app la pagarà automàticament per{' '}
          <span className="num font-semibold text-foreground">{formatMoney(row.clauseValue)}</span>. Si {row.ownerTeamName || 'el rival'} la puja abans,
          et demanarà confirmació. La pots cancel·lar quan vulguis.
        </>
      ),
      confirmLabel: 'Programar compra',
      tone: 'neutral',
      run: async () => {
        await apiClient.post('/clause-orders', { fantasy_player_id: row.playerId })
        setOrdersKey((k) => k + 1)
        return `Compra automàtica de ${row.playerName} programada.`
      },
    })
  const [error, setError] = useState('')

  const [search, setSearch] = useState('')
  const [filters, setFilters] = usePersistentState('clauses-filters', DEFAULT_FILTERS)
  const [sort, setSort] = usePersistentState('clauses-sort', { key: 'clauseEconomicScore', direction: 'desc' })
  const { position, onlyRecommended, onlyLocked } = { ...DEFAULT_FILTERS, ...filters }
  const setFilter = (key, value) => setFilters((f) => ({ ...DEFAULT_FILTERS, ...f, [key]: value }))
  const filtersActive = search !== '' || JSON.stringify({ ...DEFAULT_FILTERS, ...filters }) !== JSON.stringify(DEFAULT_FILTERS)
  const clearFilters = () => {
    setSearch('')
    setFilters(DEFAULT_FILTERS)
  }

  const load = () => {
    setError('')
    cachedGet('/clauses/opportunities', null, setPayload).catch((err) =>
      setError(err.response?.data?.message || "No s'han pogut carregar les clàusules."),
    )
  }

  useEffect(load, [])
  useAppEvent(DATA_CHANGED, load)

  const rows = useMemo(() => payload?.data || [], [payload])

  const filtered = useMemo(() => {
    return rows.filter((r) => {
      if (search && !r.playerName.toLowerCase().includes(search.toLowerCase())) return false
      if (position && r.position !== position) return false
      if (onlyRecommended && r.economicRecommendation !== 'PAY_CLAUSE') return false
      if (onlyLocked && !r.isLocked) return false
      return true
    })
  }, [rows, search, position, onlyRecommended, onlyLocked])

  const sorted = useMemo(() => {
    const dir = sort.direction === 'asc' ? 1 : -1
    const accessor = SORT_ACCESSORS[sort.key] || SORT_ACCESSORS.clauseEconomicScore
    const daysUntilUnlock = SORT_ACCESSORS.daysUntilUnlock
    return [...filtered].sort((a, b) => {
      const av = accessor(a)
      const bv = accessor(b)
      let cmp
      if (av === null || av === undefined || av === '') cmp = 1
      else if (bv === null || bv === undefined || bv === '') cmp = -1
      else if (typeof av === 'string') cmp = av.localeCompare(bv) * dir
      else cmp = av > bv ? dir : av < bv ? -dir : 0

      // Tie-break (and, for the days-to-unlock column itself, the primary
      // order) always goes from fewer to more days left to unlock.
      if (cmp !== 0) return cmp
      return daysUntilUnlock(a) - daysUntilUnlock(b)
    })
  }, [filtered, sort])

  const toggleSort = (key) => {
    setSort((s) => (s.key === key ? { key, direction: s.direction === 'desc' ? 'asc' : 'desc' } : { key, direction: 'desc' }))
  }

  const sortArrow = (key) => (sort.key === key ? (sort.direction === 'desc' ? ' ↓' : ' ↑') : '')

  if (error && !payload) return <ErrorState message={error} onRetry={load} />
  if (!payload) return <PageSkeleton cards={0} rows={8} />

  const recommended = rows.filter((r) => r.economicRecommendation === 'PAY_CLAUSE').sort((a, b) => b.clauseEconomicScore - a.clauseEconomicScore)
  const top = recommended[0] || null

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Clàusules</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {rows.length} jugadors rivals amb clàusula analitzats · {recommended.length} amb recomanació de pagar
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        Només mira els diners: si el sobrecost que pagues avui es recuperaria amb la pujada prevista del valor. No mira si juga ni si el necessites.
      </p>

      {payload.available === false && (
        <div className="mt-4 panel p-6 text-sm text-muted-foreground">{payload.message}</div>
      )}

      <ActionModal request={pendingAction} onClose={() => setPendingAction(null)} />
      <ClauseOrdersPanel key={ordersKey} />
      <ClauseRadar rows={rows} onSchedule={scheduleOrder} />

      {top && (
        <div className="mt-5 flex flex-col gap-6 rounded-2xl border border-info/30 bg-info/5 p-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-info/15 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-info">
                <span className="h-1.5 w-1.5 rounded-full bg-info" />
                Millor oportunitat
              </span>
              <span className={`rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${CLASSIFICATION_BADGE[top.classification]}`}>
                {CLASSIFICATION_LABELS[top.classification]}
              </span>
            </div>
            <p className="mt-3 text-2xl font-bold">{top.playerName}</p>
            <p className="text-sm text-muted-foreground">
              {top.club || 'Club desconegut'} · {POSITION_LABELS[top.position] || top.position} · propietat de{' '}
              {top.ownerTeamName || 'un rival'}
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <p className="num text-3xl font-extrabold">{formatMoneyM(top.clauseValue)}</p>
              <span className={`num rounded-md px-2 py-1 text-sm font-semibold ${premiumClass(top.clausePremiumPct)} bg-current/10`}>
                {formatPercent(top.clausePremiumPct * 100)} vs. valor de mercat
              </span>
            </div>
            <p className="mt-2 text-sm text-muted-foreground">
              Rendiment a 14 dies: <span className={`num font-semibold ${changeClass(top.roi14d)}`}>{formatPercent(top.roi14d * 100)}</span>
              {' '}· Recupera el sobrecost: <span className="font-semibold text-foreground">{breakEvenLabel(top.breakEvenDays)}</span>
            </p>
            {!top.affordable && (
              <p className="mt-2 flex items-center gap-1.5 text-sm text-warn">
                <WalletIcon className="h-3.5 w-3.5" />
                Recomanada igualment, però ara mateix no tens prou capital disponible per pagar-la.
              </p>
            )}
            {top.isLocked && (
              <p className="mt-2 flex items-center gap-1.5 text-sm text-warn">
                <ShieldIcon className="h-3.5 w-3.5" />
                Recomanada igualment, encara que la clàusula està blindada {top.daysUntilUnlock} {top.daysUntilUnlock === 1 ? 'dia' : 'dies'} més.
              </p>
            )}
            {top.history?.length > 1 && (
              <div className="mt-3 max-w-xs">
                <Sparkline data={top.history} height={48} />
              </div>
            )}
          </div>

          <ScoreRing
            score={top.clauseEconomicScore}
            title="Valoració econòmica"
            tierLabel={CLASSIFICATION_LABELS[top.classification]}
            tierColor={CLASSIFICATION_RING_COLOR[top.classification]}
          />

          <div className="grid grid-cols-2 gap-3 lg:w-72">
            <div className="rounded-xl bg-info/10 p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-info/80">Valoració econòmica</p>
              <p className="mt-1 text-xl font-bold text-info">{top.clauseEconomicScore}/100</p>
            </div>
            <div className="rounded-xl border border-border p-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Valor previst 14 dies</p>
              <p className="mt-1 text-xl font-bold">{formatMoneyM(top.expectedValue14d)}</p>
            </div>
            <Link
              to={`/players/${top.playerId}`}
              className="col-span-2 flex items-center justify-center gap-1.5 rounded-lg bg-info/20 py-2.5 text-sm font-semibold text-info hover:bg-info/30"
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

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filtre</p>
            <div className="flex gap-2">
              <Pill active={onlyRecommended} onClick={() => setFilter('onlyRecommended', !onlyRecommended)}>
                Només recomanades
              </Pill>
              <Pill active={onlyLocked} onClick={() => setFilter('onlyLocked', !onlyLocked)}>
                Amagar desbloquejades
              </Pill>
            </div>
          </div>
        </div>
        {filtersActive && (
          <button onClick={clearFilters} className="mt-4 text-sm font-semibold text-primary hover:underline">
            Treure filtres
          </button>
        )}
      </div>

      <div className="mt-4 hidden overflow-x-auto panel md:block">
        <table className="w-full min-w-260 text-sm">
          <thead>
            <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('change24h')}>
                <span className="inline-flex items-center gap-1">
                  24H{sortArrow('change24h')}
                  <InfoTip>Pujada o baixada del valor de mercat en les últimes 24 hores, en euros. «*ext.» vol dir que la dada és de futbolfantasy.com (font externa, no oficial).</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('player')}>
                Jugador{sortArrow('player')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('position')}>
                Pos.{sortArrow('position')}
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('owner')}>
                <span className="inline-flex items-center gap-1">
                  Propietari{sortArrow('owner')}
                  <InfoTip>Equip rival que té el jugador ara mateix.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('marketValue')}>
                <span className="inline-flex items-center gap-1">
                  Valor{sortArrow('marketValue')}
                  <InfoTip>Valor de mercat actual del jugador segons LaLiga Fantasy.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('pctChange7d')}>
                <span className="inline-flex items-center gap-1">
                  7D{sortArrow('pctChange7d')}
                  <InfoTip>Evolució del valor en els últims 7 dies. Si encara no tenim prou historial propi, fem servir futbolfantasy.com (marcat amb *ext.).</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('clauseValue')}>
                <span className="inline-flex items-center gap-1">
                  Clàusula{sortArrow('clauseValue')}
                  <InfoTip>El que hauries de pagar per fitxar-lo pagant la clàusula.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('daysUntilUnlock')}>
                <span className="inline-flex items-center gap-1">
                  Desbloqueig{sortArrow('daysUntilUnlock')}
                  <InfoTip>Dies que falten perquè la clàusula es pugui pagar. És informatiu: no canvia la recomanació.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('premium')}>
                <span className="inline-flex items-center gap-1">
                  Sobrecost{sortArrow('premium')}
                  <InfoTip>Quant més car que el seu valor de mercat et surt pagar la clàusula: (clàusula − valor) / valor.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('roi14d')}>
                <span className="inline-flex items-center gap-1">
                  Rendiment 14d{sortArrow('roi14d')}
                  <InfoTip>Quant guanyaries (o perdries) d'aquí a 14 dies sobre el que pagues: (valor previst a 14 dies − clàusula) / clàusula.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('breakEvenDays')}>
                <span className="inline-flex items-center gap-1">
                  Recuperació{sortArrow('breakEvenDays')}
                  <InfoTip>Dies que trigaria el valor del jugador a arribar al preu que pagues. «Sense recuperació» si no hi arriba en 30 dies.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('clauseEconomicScore')}>
                <span className="inline-flex items-center gap-1">
                  Valoració{sortArrow('clauseEconomicScore')}
                  <InfoTip>Nota econòmica de 0 a 100: combina el rendiment a 14 dies i com de ràpid recuperes el sobrecost. No mira si juga ni si el necessites.</InfoTip>
                </span>
              </th>
              <th className="cursor-pointer select-none px-4 py-3 hover:text-foreground" onClick={() => toggleSort('economicRecommendation')}>
                <span className="inline-flex items-center gap-1">
                  Acció{sortArrow('economicRecommendation')}
                  <InfoTip>Recomanació segons la valoració: Pagar clàusula (75 o més), A considerar (60-74), Esperar (40-59), No pagar (menys de 40).</InfoTip>
                </span>
              </th>
            </tr>
          </thead>
          <tbody className="stagger-rows">
            {sorted.map((row) => (
              <tr
                key={row.playerId + '-' + row.ownerTeamId}
                className={`border-b border-border last:border-0 hover:bg-surface-raised ${row.economicRecommendation === 'PAY_CLAUSE' ? 'bg-info/5' : ''}`}
              >
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
                  <Link to={`/players/${row.playerId}`} className="flex items-center gap-2.5 hover:text-primary">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[11px] font-bold text-muted-foreground">
                      {row.imageUrl ? <img src={row.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.playerName)}
                    </div>
                    <div className="min-w-0">
                      <p className="truncate font-medium">{row.playerName}</p>
                      <p className="truncate text-xs text-muted-foreground">{row.club || '—'}</p>
                    </div>
                  </Link>
                </td>
                <td className="px-4 py-3">
                  <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
                    {POSITION_LABELS[row.position] || row.position}
                  </span>
                </td>
                <td className="px-4 py-3 text-muted-foreground">{row.ownerTeamName || '—'}</td>
                <td className="px-4 py-3 font-medium">{formatMoneyM(row.marketValue)}</td>
                <td className="w-24 px-4 py-3">
                  <Sparkline data={row.history} />
                  {(() => {
                    const pct7d = row.trend?.pctChange7d ?? row.externalTrend?.pct7d
                    return pct7d != null ? (
                      <p className={`text-xs font-medium ${changeClass(pct7d)}`}>
                        {pct7d >= 0 ? '↗' : '↘'} {formatPercent(pct7d)}
                        {row.historySource === 'external' && <span className="text-muted-foreground"> *ext.</span>}
                      </p>
                    ) : (
                      row.historySource === 'external' && <span className="text-[11px] text-muted-foreground">*ext.</span>
                    )
                  })()}
                </td>
                <td className="px-4 py-3 font-medium">
                  <span className="inline-flex items-center gap-1">
                    {formatMoneyM(row.clauseValue)}
                    {(row.isLocked || row.isShielded) && <ShieldIcon className="h-3.5 w-3.5 text-muted-foreground" aria-label="Clàusula blindada" />}
                  </span>
                </td>
                <td className="px-4 py-3">
                  {row.isLocked ? (
                    <span className="rounded-md bg-muted-foreground/15 px-2 py-1 text-xs font-bold text-muted-foreground">
                      {row.daysUntilUnlock} {row.daysUntilUnlock === 1 ? 'dia' : 'dies'}
                    </span>
                  ) : (
                    <span className="text-xs text-muted-foreground">Desbloquejada</span>
                  )}
                </td>
                <td className={`px-4 py-3 font-medium ${premiumClass(row.clausePremiumPct)}`}>
                  {row.clausePremiumPct !== null ? formatPercent(row.clausePremiumPct * 100) : '—'}
                </td>
                <td className={`px-4 py-3 font-medium ${changeClass(row.roi14d)}`}>
                  {row.roi14d !== null ? formatPercent(row.roi14d * 100) : '—'}
                  {row.expectedValue14d != null && (
                    <span className="block text-[11px] font-normal text-muted-foreground">previst {formatMoneyM(row.expectedValue14d)}</span>
                  )}
                </td>
                <td className="px-4 py-3 text-muted-foreground">{breakEvenLabel(row.breakEvenDays)}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(row.clauseEconomicScore)}`}>
                    {row.clauseEconomicScore}
                  </span>
                  <span className="mt-1 block text-[11px] text-muted-foreground">{CLASSIFICATION_LABELS[row.classification]}</span>
                </td>
                <td className="px-4 py-3">
                  <span className="inline-flex items-center gap-1.5">
                    <span className={`rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[row.economicRecommendation]}`}>
                      {ACTION_LABELS[row.economicRecommendation]}
                    </span>
                    {row.economicRecommendation === 'PAY_CLAUSE' && !row.affordable && (
                      <InfoTip label="Sense prou capital">
                        <span className="flex items-center gap-1.5">
                          <WalletIcon className="h-3.5 w-3.5 text-warn" /> Ara mateix no tens prou capital disponible per pagar-la.
                        </span>
                      </InfoTip>
                    )}
                    {row.economicRecommendation === 'PAY_CLAUSE' && row.isLocked && (
                      <InfoTip label="Clàusula blindada">
                        <span className="flex items-center gap-1.5">
                          <ShieldIcon className="h-3.5 w-3.5 text-warn" /> Encara blindada {row.daysUntilUnlock} {row.daysUntilUnlock === 1 ? 'dia' : 'dies'} més.
                        </span>
                      </InfoTip>
                    )}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {sorted.length === 0 && (
          <EmptyState
            icon={SearchX}
            className="m-4"
            title={rows.length === 0 ? 'Encara no hi ha dades de clàusules' : 'Cap jugador amb aquests filtres'}
            message={rows.length === 0 ? 'Apareixeran després de la propera sincronització.' : 'Prova de treure algun filtre.'}
            actionLabel={filtersActive && rows.length > 0 ? 'Treure filtres' : undefined}
            onAction={clearFilters}
          />
        )}
      </div>

      <div className="stagger mt-4 space-y-3 md:hidden">
        {sorted.map((row) => (
          <ClauseCard key={row.playerId + '-' + row.ownerTeamId} row={row} />
        ))}
        {sorted.length === 0 && (
          <EmptyState
            icon={SearchX}
           
            title={rows.length === 0 ? 'Encara no hi ha dades de clàusules' : 'Cap jugador amb aquests filtres'}
            message={rows.length === 0 ? 'Apareixeran després de la propera sincronització.' : 'Prova de treure algun filtre.'}
            actionLabel={filtersActive && rows.length > 0 ? 'Treure filtres' : undefined}
            onAction={clearFilters}
          />
        )}
      </div>

      <p className="mt-4 flex items-center gap-1.5 text-xs text-muted-foreground">
        <WalletIcon className="h-3.5 w-3.5" />
        Capital disponible per pagar clàusules: {formatMoneyM(rows[0]?.availableCapital)}
      </p>
    </div>
  )
}
