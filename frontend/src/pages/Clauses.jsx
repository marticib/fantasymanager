import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import ScoreRing from '../components/ScoreRing'
import Sparkline from '../components/Sparkline'
import { ShieldIcon, WalletIcon, ArrowRightIcon } from '../components/Icons'
import { formatMoneyM, formatDelta, formatPercent, POSITION_LABELS, initials, ACTION_LABELS } from '../utils/format'

const POSITIONS = ['GK', 'DF', 'MF', 'FW']

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
      className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
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
          ROI 14D <span className={`font-semibold ${changeClass(row.roi14d)}`}>{row.roi14d !== null ? formatPercent(row.roi14d * 100) : '—'}</span>
        </span>
      </div>
    </Link>
  )
}

export default function Clauses() {
  const [payload, setPayload] = useState(null)
  const [error, setError] = useState('')

  const [search, setSearch] = useState('')
  const [position, setPosition] = useState(null)
  const [onlyRecommended, setOnlyRecommended] = useState(false)
  const [onlyLocked, setOnlyLocked] = useState(true)
  const [sort, setSort] = useState({ key: 'clauseEconomicScore', direction: 'desc' })

  useEffect(() => {
    apiClient
      .get('/clauses/opportunities')
      .then((res) => setPayload(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant les clàusules.'))
  }, [])

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

  if (error) return <p className="text-bear">{error}</p>
  if (!payload) return <p className="text-muted-foreground">Carregant…</p>

  const recommended = rows.filter((r) => r.economicRecommendation === 'PAY_CLAUSE').sort((a, b) => b.clauseEconomicScore - a.clauseEconomicScore)
  const top = recommended[0] || null

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Clàusules</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {rows.length} jugadors rivals amb clàusula analitzats · {recommended.length} amb recomanació de pagar
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        Anàlisi purament econòmica: si la prima pagada avui es recuperaria amb l'evolució prevista del valor de mercat. No té en compte el
        Fantasy Score ni la teva plantilla.
      </p>

      {payload.available === false && (
        <div className="mt-4 rounded-2xl border border-border bg-surface p-6 text-sm text-muted-foreground">{payload.message}</div>
      )}

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
              ROI a 14 dies: <span className={`num font-semibold ${changeClass(top.roi14d)}`}>{formatPercent(top.roi14d * 100)}</span>
              {' '}· Recupera la prima: <span className="font-semibold text-foreground">{breakEvenLabel(top.breakEvenDays)}</span>
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
            title="Economic Score"
            tierLabel={CLASSIFICATION_LABELS[top.classification]}
            tierColor={CLASSIFICATION_RING_COLOR[top.classification]}
          />

          <div className="grid grid-cols-2 gap-3 lg:w-72">
            <div className="rounded-xl bg-info/10 p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-info/80">Economic Score</p>
              <p className="mt-1 text-xl font-bold text-info">{top.clauseEconomicScore}/100</p>
            </div>
            <div className="rounded-xl border border-border p-4">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Valor previst 14D</p>
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

          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filtre</p>
            <div className="flex gap-2">
              <Pill active={onlyRecommended} onClick={() => setOnlyRecommended((v) => !v)}>
                Només recomanades
              </Pill>
              <Pill active={onlyLocked} onClick={() => setOnlyLocked((v) => !v)}>
                Amagar desbloquejades
              </Pill>
            </div>
          </div>
        </div>
      </div>

      <div className="mt-4 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-260 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('change24h')}
                title="Pujada o baixada de valor de mercat en les últimes 24 hores, en euros"
              >
                24H{sortArrow('change24h')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('player')}
                title="Jugador rival amb clàusula de rescissió activa"
              >
                Jugador{sortArrow('player')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('position')}
                title="Posició al camp"
              >
                Pos.{sortArrow('position')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('owner')}
                title="Equip rival que té el jugador actualment"
              >
                Propietari{sortArrow('owner')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('marketValue')}
                title="Valor de mercat actual del jugador segons LaLiga Fantasy"
              >
                Valor{sortArrow('marketValue')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('pctChange7d')}
                title="Evolució del valor de mercat en els últims 7 dies (línia i percentatge). Font pròpia quan n'hi ha prou historial; si no, futbolfantasy.com (marcat amb *ext.)"
              >
                7D{sortArrow('pctChange7d')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('clauseValue')}
                title="Import que hauries de pagar per rescindir la clàusula i fitxar el jugador"
              >
                Clàusula{sortArrow('clauseValue')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('daysUntilUnlock')}
                title="Dies que falten perquè la clàusula deixi d'estar blindada temporalment i es pugui pagar (informatiu, no afecta la recomanació)"
              >
                Desbloqueig{sortArrow('daysUntilUnlock')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('premium')}
                title="Diferència entre el preu de la clàusula i el valor de mercat actual: (clàusula − valor) / valor"
              >
                Prima{sortArrow('premium')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('roi14d')}
                title="Retorn previst sobre el que pagaries per la clàusula, a 14 dies: (valor projectat a 14 dies − clàusula) / clàusula"
              >
                ROI 14D{sortArrow('roi14d')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('breakEvenDays')}
                title="Dies perquè el valor projectat del jugador arribi al preu de la clàusula (recuperis la prima pagada). «Sense recuperació» si no s'hi arriba en 30 dies"
              >
                Recuperació{sortArrow('breakEvenDays')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('clauseEconomicScore')}
                title="Clause Economic Score (0-100): combina el ROI a 14 dies i la rapidesa de recuperació de la prima. No té en compte el Fantasy Score ni la teva plantilla"
              >
                Score{sortArrow('clauseEconomicScore')}
              </th>
              <th
                className="cursor-pointer select-none px-4 py-3 hover:text-foreground"
                onClick={() => toggleSort('economicRecommendation')}
                title="Recomanació purament econòmica segons el Score: Pagar clàusula (≥75), A considerar (60-74), Esperar (40-59), No pagar (<40)"
              >
                Acció{sortArrow('economicRecommendation')}
              </th>
            </tr>
          </thead>
          <tbody>
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
                  <Link to={`/players/${row.playerId}`} className="flex items-center gap-2.5 hover:text-primary">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
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
                <td
                  className="w-24 px-4 py-3"
                  title={
                    row.historySource === 'external'
                      ? "Línia reconstruïda amb dades de futbolfantasy.com (no oficial) — encara no tenim prou historial propi"
                      : undefined
                  }
                >
                  <Sparkline data={row.history} />
                  {(() => {
                    const pct7d = row.trend?.pctChange7d ?? row.externalTrend?.pct7d
                    return pct7d != null ? (
                      <p className={`text-xs font-medium ${changeClass(pct7d)}`}>
                        {pct7d >= 0 ? '↗' : '↘'} {formatPercent(pct7d)}
                        {row.historySource === 'external' && <span className="text-muted-foreground"> *ext.</span>}
                      </p>
                    ) : (
                      row.historySource === 'external' && <span className="text-[9px] text-muted-foreground">*ext.</span>
                    )
                  })()}
                </td>
                <td className="px-4 py-3 font-medium">
                  <span className="inline-flex items-center gap-1">
                    {formatMoneyM(row.clauseValue)}
                    {(row.isLocked || row.isShielded) && (
                      <ShieldIcon className="h-3.5 w-3.5 text-muted-foreground" title="Clàusula blindada" />
                    )}
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
                <td
                  className={`px-4 py-3 font-medium ${changeClass(row.roi14d)}`}
                  title={`Valor previst a 14 dies: ${formatMoneyM(row.expectedValue14d)}`}
                >
                  {row.roi14d !== null ? formatPercent(row.roi14d * 100) : '—'}
                </td>
                <td className="px-4 py-3 text-muted-foreground">{breakEvenLabel(row.breakEvenDays)}</td>
                <td className="px-4 py-3">
                  <span
                    className={`rounded-md px-2 py-1 text-xs font-bold ${scoreBadgeClass(row.clauseEconomicScore)}`}
                    title={CLASSIFICATION_LABELS[row.classification]}
                  >
                    {row.clauseEconomicScore}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <span className="inline-flex items-center gap-1.5">
                    <span className={`rounded-md px-2 py-1 text-xs font-bold ${ACTION_BADGE[row.economicRecommendation]}`}>
                      {ACTION_LABELS[row.economicRecommendation]}
                    </span>
                    {row.economicRecommendation === 'PAY_CLAUSE' && !row.affordable && (
                      <WalletIcon className="h-3.5 w-3.5 text-warn" title="No tens prou capital disponible ara mateix" />
                    )}
                    {row.economicRecommendation === 'PAY_CLAUSE' && row.isLocked && (
                      <ShieldIcon
                        className="h-3.5 w-3.5 text-warn"
                        title={`Encara blindada ${row.daysUntilUnlock} ${row.daysUntilUnlock === 1 ? 'dia' : 'dies'} més`}
                      />
                    )}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {sorted.length === 0 && (
          <p className="p-6 text-center text-sm text-muted-foreground">
            {rows.length === 0 ? 'Cap dada de clàusules disponible encara.' : 'Cap jugador amb aquests filtres.'}
          </p>
        )}
      </div>

      <div className="mt-4 space-y-3 md:hidden">
        {sorted.map((row) => (
          <ClauseCard key={row.playerId + '-' + row.ownerTeamId} row={row} />
        ))}
        {sorted.length === 0 && (
          <p className="panel p-6 text-center text-sm text-muted-foreground">
            {rows.length === 0 ? 'Cap dada de clàusules disponible encara.' : 'Cap jugador amb aquests filtres.'}
          </p>
        )}
      </div>

      <p className="mt-4 flex items-center gap-1.5 text-xs text-muted-foreground">
        <WalletIcon className="h-3.5 w-3.5" />
        Capital disponible per pagar clàusules: {formatMoneyM(rows[0]?.availableCapital)}
      </p>
    </div>
  )
}
