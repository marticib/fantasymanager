import { formatDelta, formatPercent } from '../utils/format'

/** A signed euro delta with its % in parentheses — shared by History.jsx and Team.jsx's daily history strip. */
export default function ChangeBadge({ change, changePct }) {
  if (change === null || change === undefined) return <span className="text-xs text-muted-foreground">—</span>
  const positive = change > 0
  const neutral = change === 0
  const colorClass = neutral ? 'text-muted-foreground' : positive ? 'text-bull' : 'text-bear'
  return (
    <span className={`text-xs font-semibold ${colorClass}`}>
      {formatDelta(change)}
      {changePct !== null && changePct !== undefined && <span className="text-muted-foreground"> ({formatPercent(changePct)})</span>}
    </span>
  )
}
