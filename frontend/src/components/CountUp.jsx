import { useEffect, useRef, useState } from 'react'

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

/**
 * Renders a number that counts up from 0 when it first appears and glides
 * from its previous value to the new one after that (ease-out, ~0.7s) — for
 * headline figures, so a recalculation reads as a change rather than a jump.
 * `format` turns the in-between value into text.
 */
export default function CountUp({ value, format, duration = 700 }) {
  // First appearance counts up from 0; later changes glide from the previous value.
  const [shown, setShown] = useState(() => (typeof value === 'number' && !prefersReducedMotion() ? 0 : value))
  const from = useRef(0)

  useEffect(() => {
    if (value === null || value === undefined || prefersReducedMotion()) {
      from.current = value
      setShown(value)
      return undefined
    }

    const start = typeof from.current === 'number' ? from.current : 0
    const t0 = performance.now()
    let frame

    const tick = (now) => {
      const progress = Math.min(1, (now - t0) / duration)
      const eased = 1 - (1 - progress) ** 3
      const current = start + (value - start) * eased
      // Where we are right now: an interrupted run (new value, or React
      // re-running the effect) continues from here instead of jumping.
      from.current = current
      setShown(current)
      if (progress < 1) frame = requestAnimationFrame(tick)
    }

    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [value, duration])

  return format(shown === null || shown === undefined ? shown : Math.round(shown))
}
