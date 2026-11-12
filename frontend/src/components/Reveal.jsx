/**
 * Wraps content that shows up after the page has rendered (a banner fetched
 * separately, a late warning): it opens to its height smoothly instead of
 * pushing everything below it down in one jump.
 */
export default function Reveal({ children, className = '' }) {
  return (
    <div className={`grid animate-expand ${className}`}>
      <div className="min-h-0 overflow-hidden">{children}</div>
    </div>
  )
}
