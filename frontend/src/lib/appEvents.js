import { useEffect, useRef } from 'react'

// App-wide signals between the shell (Layout) and the pages.
export const DATA_CHANGED = 'fantasy:data-changed' // a sync finished — pages reload their data
export const REQUEST_SYNC = 'fantasy:request-sync' // a page asks the shell to run a sync
export const SESSION_EXPIRED = 'fantasy:laliga-session-expired' // LaLiga rejected our tokens
export const ACTION_DONE = 'fantasy:action-done' // a real action (bid, sale...) succeeded — refresh badges

export function emitAppEvent(name, detail) {
  window.dispatchEvent(new CustomEvent(name, { detail }))
}

export function useAppEvent(name, handler) {
  const ref = useRef(handler)
  ref.current = handler

  useEffect(() => {
    const listener = (e) => ref.current(e.detail)
    window.addEventListener(name, listener)
    return () => window.removeEventListener(name, listener)
  }, [name])
}
