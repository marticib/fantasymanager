/** Shimmering placeholder shaped like a page (title, stat cards, rows) while data loads. */
export function PageSkeleton({ cards = 3, rows = 6 }) {
  return (
    <div className="animate-skeleton-in" aria-busy="true" aria-label="Carregant">
      <div className="skeleton h-8 w-48 rounded-lg" />
      <div className="skeleton mt-2 h-4 w-72 max-w-full rounded opacity-70" />
      {cards > 0 && (
        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
          {Array.from({ length: cards }, (_, i) => (
            <div key={i} className="panel h-24" />
          ))}
        </div>
      )}
      <div className="mt-6 space-y-2">
        {Array.from({ length: rows }, (_, i) => (
          <div key={i} className="panel flex h-16 items-center gap-3 px-4">
            <div className="skeleton h-8 w-8 shrink-0 rounded-full" />
            <div className="skeleton h-3 flex-1 rounded" />
            <div className="skeleton h-3 w-16 rounded" />
          </div>
        ))}
      </div>
    </div>
  )
}

/** Inline placeholder for a block inside a page (a list or chart still loading). */
export function BlockSkeleton({ rows = 3 }) {
  return (
    <div className="animate-skeleton-in space-y-2 p-4" aria-busy="true" aria-label="Carregant">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="skeleton h-4 rounded" style={{ width: `${90 - i * 15}%` }} />
      ))}
    </div>
  )
}

/** A friendly "nothing here" message with an optional way out. */
export function EmptyState({ icon: Icon, title, message, actionLabel, onAction, className = '' }) {
  return (
    <div className={`panel flex animate-enter flex-col items-center px-6 py-12 text-center ${className}`}>
      {Icon && (
        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-surface-raised text-muted-foreground ring-1 ring-border">
          <Icon className="h-5 w-5" />
        </div>
      )}
      <p className="mt-3 font-semibold">{title}</p>
      {message && <p className="mt-1 max-w-md text-sm text-muted-foreground">{message}</p>}
      {actionLabel && onAction && (
        <button
          onClick={onAction}
          className="mt-4 min-h-11 rounded-lg border border-border px-4 text-sm font-semibold hover:border-primary/50 hover:text-primary"
        >
          {actionLabel}
        </button>
      )}
    </div>
  )
}

/** A load error with a retry button instead of a dead red line. */
export function ErrorState({ message, onRetry }) {
  return (
    <div className="rounded-xl border border-bear/30 bg-bear/10 px-5 py-4">
      <p className="text-sm font-semibold text-bear">{message}</p>
      {onRetry && (
        <button onClick={onRetry} className="mt-3 min-h-10 rounded-lg bg-bear/20 px-3 text-sm font-semibold text-bear hover:bg-bear/30">
          Tornar-ho a provar
        </button>
      )}
    </div>
  )
}
