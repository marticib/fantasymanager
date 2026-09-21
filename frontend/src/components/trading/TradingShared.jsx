import { AlertTriangle, Clock, Database } from 'lucide-react'
import { HORIZON_LABELS } from './tradingData'
import { formatPercent, formatRelativeTime, initials, POSITION_LABELS } from '../../utils/format'

export function Pill({ active, onClick, children, title }) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      className={`rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors ${
        active ? 'border-primary bg-primary/15 text-primary' : 'border-border text-muted-foreground hover:border-primary/50'
      }`}
    >
      {children}
    </button>
  )
}

export function HorizonFilter({ value, options, onChange }) {
  return (
    <div className="flex items-center gap-2" role="group" aria-label="Horitzó temporal">
      <span className="text-xs uppercase tracking-wide text-muted-foreground">Horitzó</span>
      {options.map((h) => (
        <Pill key={h} active={value === h} onClick={() => onChange(h)}>
          {HORIZON_LABELS[h] || `${h}D`}
        </Pill>
      ))}
    </div>
  )
}

const TONES = { bull: 'text-bull', bear: 'text-bear', warn: 'text-warn', info: 'text-info', muted: 'text-muted-foreground' }

export function StatCard({ label, value, sub, tone = 'default' }) {
  return (
    <div className="panel p-4">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`num mt-1 text-xl font-semibold ${TONES[tone] || ''}`}>{value}</p>
      {sub && <p className="mt-1 text-xs text-muted-foreground">{sub}</p>}
    </div>
  )
}

const CONFIDENCE_STYLES = {
  HIGH: 'bg-bull-soft text-bull',
  MEDIUM: 'bg-warn-soft text-warn',
  LOW: 'bg-bear-soft text-bear',
}
const CONFIDENCE_LABELS = { HIGH: 'alta', MEDIUM: 'mitjana', LOW: 'baixa' }
const COMPONENT_LABELS = {
  match: 'Coincidència FF',
  windows: 'Finestres 1D/3D/7D',
  freshness: 'Frescor FF',
  cost_certainty: 'Certesa del preu',
  value_consistency: 'Coherència de valors',
}

export function ConfidenceBadge({ confidence }) {
  if (!confidence) return <span className="text-xs text-muted-foreground">—</span>
  const detail = Object.entries(confidence.components || {})
    .map(([key, score]) => `${COMPONENT_LABELS[key] || key}: ${score}`)
    .join(' · ')
  return (
    <span
      title={detail}
      className={`num inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${CONFIDENCE_STYLES[confidence.level] || ''}`}
    >
      {confidence.score} · {CONFIDENCE_LABELS[confidence.level]}
    </span>
  )
}

const ORIGIN_STYLES = {
  OWN: 'bg-surface-raised text-muted-foreground',
  MARKET: 'bg-warn-soft text-warn',
  CLAUSE: 'bg-info-soft text-info',
  BUY: 'bg-warn-soft text-warn',
  SELL: 'bg-bear-soft text-bear',
  HOLD: 'bg-surface-raised text-muted-foreground',
}
const ORIGIN_LABELS = { OWN: 'PROPI', MARKET: 'MERCAT', CLAUSE: 'CLÀUSULA', BUY: 'COMPRAR', SELL: 'VENDRE', HOLD: 'MANTENIR' }

export function OriginBadge({ kind }) {
  return <span className={`rounded-md px-2 py-0.5 text-xs font-bold ${ORIGIN_STYLES[kind] || ''}`}>{ORIGIN_LABELS[kind] || kind}</span>
}

export function PlayerIdentity({ row }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
        {row.imageUrl ? <img src={row.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.name)}
      </div>
      <div className="min-w-0">
        <p className="truncate font-semibold">{row.name}</p>
        <p className="truncate text-xs text-muted-foreground">
          {row.club || '—'} · {POSITION_LABELS[row.position] || row.position || '—'}
        </p>
      </div>
    </div>
  )
}

function pctChip(label, value) {
  if (value === null || value === undefined) return null
  const tone = value > 0 ? 'text-bull' : value < 0 ? 'text-bear' : 'text-muted-foreground'
  return (
    <span key={label} className={`num ${tone}`}>
      {label} {formatPercent(value)}
    </span>
  )
}

/** futbolfantasy's own trend for the player, or an explicit "no data" — never a made-up zero. */
export function ExternalTrend({ external }) {
  if (!external || external.matchStatus !== 'MATCHED') {
    return <span className="text-xs text-warn">Sense coincidència a FútbolFantasy</span>
  }
  const chips = [pctChip('1D', external.pct1d), pctChip('3D', external.pct3d), pctChip('7D', external.pct7d)].filter(Boolean)
  return (
    <span className="flex flex-wrap items-center gap-x-2 text-xs">
      {chips.length ? chips : <span className="text-muted-foreground">Tendència FF sense %</span>}
      {external.decelerating && <span className="text-warn">↘ desaccelera</span>}
    </span>
  )
}

export function Metric({ label, value, tone = 'default', sub }) {
  return (
    <div className="min-w-0">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`num text-sm font-semibold ${TONES[tone] || ''}`}>{value}</p>
      {sub && <p className="text-[11px] text-muted-foreground">{sub}</p>}
    </div>
  )
}

const WARNING_LABELS = {
  PARTIAL_HISTORY: 'Historial FF parcial',
  VALUE_DIVERGENCE: 'Valor FF ≠ valor LaLiga',
  NO_MATCH: 'Sense dades FF',
  INSUFFICIENT_FF_DATA: 'Dades FF insuficients',
  SUSPECT_VALUE_MISMATCH: 'Coincidència sospitosa',
}

export function WarningChips({ warnings }) {
  if (!warnings?.length) return null
  return (
    <div className="mt-2 flex flex-wrap gap-1.5">
      {warnings.map((w) => (
        <span key={w} className="inline-flex items-center gap-1 rounded-md bg-warn-soft px-1.5 py-0.5 text-[11px] text-warn">
          <AlertTriangle className="h-3 w-3" aria-hidden="true" />
          {WARNING_LABELS[w] || w}
        </span>
      ))}
    </div>
  )
}

const SOURCE_LABELS = { league: 'Plantilla i caixa', market: 'Mercat', clauses: 'Clàusules', external: 'FútbolFantasy' }

/** Per-source last-sync ages, with the stale ones called out as warnings. */
export function FreshnessBanner({ freshness, matching }) {
  if (!freshness) return null
  const stale = freshness.warnings || []
  return (
    <div className={`rounded-xl border p-3 text-xs ${stale.length ? 'border-warn/30 bg-warn-soft' : 'border-border bg-surface'}`}>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span className="inline-flex items-center gap-1 font-medium text-muted-foreground">
          <Clock className="h-3.5 w-3.5" aria-hidden="true" /> Dades
        </span>
        {Object.entries(freshness.sources).map(([key, source]) => (
          <span key={key} className={source.stale ? 'font-semibold text-warn' : 'text-muted-foreground'}>
            {SOURCE_LABELS[key]}: {source.lastUpdatedAt ? formatRelativeTime(source.lastUpdatedAt) : 'mai'}
          </span>
        ))}
        {matching && (
          <span className="inline-flex items-center gap-1 text-muted-foreground">
            <Database className="h-3.5 w-3.5" aria-hidden="true" />
            FF: {matching.matched}/{matching.total} identificats
            {matching.noMatch > 0 && ` · ${matching.noMatch} sense coincidència`}
          </span>
        )}
      </div>
      {stale.length > 0 && (
        <ul className="mt-2 space-y-0.5 text-warn">
          {stale.map((w) => (
            <li key={w.source}>⚠ {w.message}</li>
          ))}
        </ul>
      )}
    </div>
  )
}

/** A full-width notice for the special states (empty, impossible, keep cash, ...). */
export function StateNotice({ tone = 'info', title, children }) {
  const styles = {
    info: 'border-info/30 bg-info-soft',
    warn: 'border-warn/30 bg-warn-soft',
    bear: 'border-bear/30 bg-bear-soft',
    bull: 'border-bull/30 bg-bull-soft',
    muted: 'border-border bg-surface',
  }
  return (
    <div className={`rounded-xl border p-5 ${styles[tone]}`} role="status">
      <h3 className="text-lg font-bold tracking-tight">{title}</h3>
      {children && <div className="mt-2 space-y-1 text-sm text-muted-foreground">{children}</div>}
    </div>
  )
}

/** Market / clause / cash split of the capital, as one stacked bar plus a legend. */
export function DistributionBar({ distribution }) {
  if (!distribution) return null
  const segments = [
    { key: 'marketPct', label: 'Mercat', className: 'bg-warn' },
    { key: 'clausePct', label: 'Clàusules', className: 'bg-info' },
    { key: 'cashPct', label: 'Caixa', className: 'bg-muted-foreground/40' },
  ]
  if (segments.every((s) => distribution[s.key] === null || distribution[s.key] === undefined)) return null
  return (
    <div className="panel p-4">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">Distribució del capital</p>
      <div className="mt-3 flex h-3 overflow-hidden rounded-full bg-surface-raised" role="img" aria-label="Distribució del capital">
        {segments.map((s) => (
          <div key={s.key} className={s.className} style={{ width: `${(distribution[s.key] || 0) * 100}%` }} />
        ))}
      </div>
      <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
        {segments.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-1.5 text-muted-foreground">
            <span className={`h-2 w-2 rounded-full ${s.className}`} />
            {s.label} <span className="num font-semibold text-foreground">{formatPercent((distribution[s.key] || 0) * 100, { showSign: false })}</span>
          </span>
        ))}
      </div>
    </div>
  )
}

export function ExcludedList({ excluded }) {
  if (!excluded || excluded.total === 0) return null
  return (
    <details className="panel p-4">
      <summary className="cursor-pointer text-sm font-medium">
        {excluded.total} candidats descartats <span className="text-muted-foreground">(per què?)</span>
      </summary>
      <ul className="mt-3 space-y-1.5 text-sm">
        {excluded.items.map((row) => (
          <li key={`${row.source}-${row.playerId}`} className="flex flex-wrap items-baseline justify-between gap-2">
            <span>
              {row.name} <span className="text-xs text-muted-foreground">({row.source === 'CLAUSE' ? 'clàusula' : 'mercat'})</span>
            </span>
            <span className="text-xs text-warn">{row.excludeLabel}</span>
          </li>
        ))}
        {excluded.total > excluded.items.length && (
          <li className="text-xs text-muted-foreground">… i {excluded.total - excluded.items.length} més.</li>
        )}
      </ul>
    </details>
  )
}
