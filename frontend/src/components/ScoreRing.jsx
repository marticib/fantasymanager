function tier(score) {
  if (score >= 80) return { label: 'MOLT BO', color: 'var(--color-bull)' }
  if (score >= 65) return { label: 'BO', color: 'var(--color-primary)' }
  if (score >= 50) return { label: 'MITJÀ', color: 'var(--color-warn)' }
  return { label: 'BAIX', color: 'var(--color-bear)' }
}

export default function ScoreRing({ score, size = 88, title = 'Fantasy Score', tierLabel, tierColor }) {
  const fallback = tier(score)
  const label = tierLabel ?? fallback.label
  const color = tierColor ?? fallback.color
  const radius = (size - 10) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference * (1 - Math.max(0, Math.min(100, score)) / 100)

  return (
    <div className="flex items-center gap-3">
      <svg width={size} height={size} className="shrink-0 -rotate-90">
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="var(--color-border)" strokeWidth={7} />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={color}
          strokeWidth={7}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
        />
        <text
          x={size / 2}
          y={size / 2}
          fill="var(--color-text)"
          fontSize="22"
          fontWeight="800"
          textAnchor="middle"
          dominantBaseline="central"
          transform={`rotate(90 ${size / 2} ${size / 2})`}
        >
          {Math.round(score)}
        </text>
      </svg>
      <div>
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</p>
        <p className="text-sm font-bold" style={{ color }}>
          {label}
        </p>
      </div>
    </div>
  )
}
