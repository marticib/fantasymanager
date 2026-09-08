import { LineChart, Line, ResponsiveContainer } from 'recharts'

export default function Sparkline({ data, positive, height = 32 }) {
  if (!data || data.length < 2) {
    return <div className="text-xs text-text-muted">—</div>
  }

  // Color always comes from the line's own first/last value by default —
  // never from a separate trend field, which can point at different data
  // than what's actually being drawn (e.g. our own trend classification
  // while the line itself fell back to an external source) and silently
  // drift out of sync with it. Pass `positive` explicitly only to override.
  const isPositive = positive ?? data[data.length - 1].value >= data[0].value
  const color = isPositive ? 'var(--color-buy)' : 'var(--color-sell)'

  return (
    <div style={{ width: '100%', height }}>
      <ResponsiveContainer width="100%" height="100%">
        <LineChart data={data}>
          <Line type="monotone" dataKey="value" stroke={color} strokeWidth={2} dot={false} isAnimationActive={false} />
        </LineChart>
      </ResponsiveContainer>
    </div>
  )
}
