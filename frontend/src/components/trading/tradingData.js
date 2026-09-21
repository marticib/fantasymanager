import { useEffect, useState } from 'react'
import apiClient from '../../api/client'

export const HORIZON_LABELS = { 3: '3D', 7: '7D', 14: '14D' }

/** Loads one Trading endpoint; re-fetches whenever the query params change. */
export function useTradingData(path, params) {
  const [state, setState] = useState({ loading: true, data: null, meta: null, error: '' })
  const key = JSON.stringify(params)

  useEffect(() => {
    let cancelled = false
    setState((prev) => ({ ...prev, loading: true, error: '' }))
    apiClient
      .get(path, { params: JSON.parse(key) })
      .then((res) => {
        if (!cancelled) setState({ loading: false, data: res.data.data, meta: res.data.meta, error: '' })
      })
      .catch((err) => {
        if (!cancelled) setState({ loading: false, data: null, meta: null, error: err.response?.data?.message || 'Error carregant les dades de trading.' })
      })
    return () => {
      cancelled = true
    }
  }, [path, key])

  return state
}

/** Sign-aware tone for a money/percentage figure; null never reads as zero. */
export function toneFor(value) {
  if (value === null || value === undefined) return 'muted'
  if (value > 0) return 'bull'
  if (value < 0) return 'bear'
  return 'muted'
}
