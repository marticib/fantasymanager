import { useEffect, useState } from 'react'

// Browser storage can throw (private mode, blocked site data) — every access is guarded.
export function readStored(storage, key, fallback) {
  try {
    const raw = storage.getItem(key)
    return raw === null ? fallback : JSON.parse(raw)
  } catch {
    return fallback
  }
}

export function writeStored(storage, key, value) {
  try {
    storage.setItem(key, JSON.stringify(value))
  } catch {
    // ignore — this is only a convenience
  }
}

/** useState that survives navigating away and back (filters, sort order...). */
export function usePersistentState(key, initial) {
  const [value, setValue] = useState(() => readStored(window.localStorage, `fantasy:${key}`, initial))

  useEffect(() => {
    writeStored(window.localStorage, `fantasy:${key}`, value)
  }, [key, value])

  return [value, setValue]
}
