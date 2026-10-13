import { useState } from 'react'

/**
 * Two-step confirm → result modal for a single real-money market action
 * (placing or cancelling a bid). Replaces a bare window.confirm() + an
 * easy-to-miss inline message with something the user actually has to
 * dismiss, so there's no ambiguity about whether the action went through.
 *
 * `request` is null when closed. Shape: { title, description (ReactNode),
 * confirmLabel, run: async () => { message } (throws on failure) }.
 */
export default function BidModal({ request, onClose }) {
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)

  if (!request) return null

  const handleConfirm = async () => {
    setBusy(true)
    try {
      const message = await request.run()
      setResult({ success: true, message })
    } catch (err) {
      setResult({ success: false, message: err.response?.data?.message || 'No s\'ha pogut completar l\'acció.' })
    } finally {
      setBusy(false)
    }
  }

  const handleClose = () => {
    const wasSuccess = result?.success
    setResult(null)
    onClose(wasSuccess)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={result ? handleClose : undefined}>
      <div className="panel elevated w-full max-w-sm p-6" onClick={(e) => e.stopPropagation()}>
        {!result ? (
          <>
            <h3 className="text-lg font-bold">{request.title}</h3>
            <div className="mt-2 text-sm text-muted-foreground">{request.description}</div>
            <div className="mt-5 flex gap-2">
              <button
                onClick={handleClose}
                disabled={busy}
                className="flex-1 rounded-lg border border-border py-2.5 text-sm font-semibold text-muted-foreground hover:text-foreground disabled:opacity-50"
              >
                Cancel·lar
              </button>
              <button
                onClick={handleConfirm}
                disabled={busy}
                className="flex-1 rounded-lg bg-bull/20 py-2.5 text-sm font-semibold text-bull hover:bg-bull/30 disabled:opacity-50"
              >
                {busy ? 'Enviant…' : request.confirmLabel}
              </button>
            </div>
          </>
        ) : (
          <>
            <h3 className={`text-lg font-bold ${result.success ? 'text-bull' : 'text-bear'}`}>
              {result.success ? 'Fet' : 'No s\'ha pogut completar'}
            </h3>
            <p className="mt-2 text-sm text-muted-foreground">{result.message}</p>
            <button
              onClick={handleClose}
              className="mt-5 w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-background hover:opacity-90"
            >
              Tancar
            </button>
          </>
        )}
      </div>
    </div>
  )
}
