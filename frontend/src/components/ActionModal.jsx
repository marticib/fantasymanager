import { useEffect, useState } from 'react'
import { ACTION_DONE, emitAppEvent } from '../lib/appEvents'

const CONFIRM_TONE = {
  positive: 'bg-bull/20 text-bull hover:bg-bull/30',
  danger: 'bg-bear/20 text-bear hover:bg-bear/30',
  neutral: 'bg-primary/20 text-primary hover:bg-primary/30',
}

/**
 * The one confirm → result popup for every real action in the app (bids,
 * offers, auto-listing...), so the user always confirms the same way and
 * always gets an explicit outcome they have to dismiss.
 *
 * `request` is null when closed. Shape: { title, description (ReactNode),
 * content? (ReactNode, e.g. an amount field), confirmLabel, tone?
 * ('positive' | 'danger' | 'neutral'), confirmDisabled?, run: async () => message }.
 * run() throwing shows the failure; onClose(wasSuccess) fires on dismiss.
 */
export default function ActionModal({ request, onClose }) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)

  const handleClose = () => {
    if (busy) return
    const wasSuccess = result?.success
    setResult(null)
    onClose(wasSuccess)
  }

  useEffect(() => {
    if (!request) return undefined
    const onKey = (e) => {
      if (e.key === 'Escape') handleClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  if (!request) return null

  const handleConfirm = async () => {
    setBusy(true)
    try {
      const message = await request.run()
      setResult({ success: true, message })
      emitAppEvent(ACTION_DONE)
    } catch (err) {
      setResult({ success: false, message: err.response?.data?.message || err.message || "No s'ha pogut completar l'acció." })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-4 sm:items-center"
      onClick={result ? handleClose : undefined}
      role="dialog"
      aria-modal="true"
    >
      <div className="panel elevated w-full max-w-sm p-6" onClick={(e) => e.stopPropagation()}>
        {!result ? (
          <>
            <h3 className="text-lg font-bold">{request.title}</h3>
            {request.description && <div className="mt-2 text-sm text-muted-foreground">{request.description}</div>}
            {request.content && <div className="mt-4">{request.content}</div>}
            <div className="mt-5 flex gap-2">
              <button
                onClick={handleClose}
                disabled={busy}
                className="min-h-11 flex-1 rounded-lg border border-border text-sm font-semibold text-muted-foreground hover:text-foreground disabled:opacity-50"
              >
                Tornar
              </button>
              <button
                onClick={handleConfirm}
                disabled={busy || request.confirmDisabled}
                autoFocus
                className={`min-h-11 flex-1 rounded-lg text-sm font-semibold disabled:opacity-50 ${CONFIRM_TONE[request.tone || 'positive']}`}
              >
                {busy ? 'Enviant…' : request.confirmLabel}
              </button>
            </div>
          </>
        ) : (
          <>
            <h3 className={`text-lg font-bold ${result.success ? 'text-bull' : 'text-bear'}`}>
              {result.success ? '✓ Fet' : "No s'ha pogut completar"}
            </h3>
            <p className="mt-2 text-sm text-muted-foreground">{result.message}</p>
            <button
              onClick={handleClose}
              autoFocus
              className="mt-5 min-h-11 w-full rounded-lg bg-primary text-sm font-semibold text-background hover:opacity-90"
            >
              Tancar
            </button>
          </>
        )}
      </div>
    </div>
  )
}
