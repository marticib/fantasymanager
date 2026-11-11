import apiClient from './client'

// Last response per URL + params, kept for the tab's lifetime: revisiting a
// page renders instantly from memory while a fresh copy loads behind it.
const cache = new Map()

function keyFor(url, params) {
  const entries = Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')
  return entries.length ? `${url}?${new URLSearchParams(entries).toString()}` : url
}

/**
 * GET with stale-while-revalidate: calls onData with the cached copy right
 * away (if there is one), then again with the fresh response. The returned
 * promise rejects like apiClient.get() so callers keep their own error handling.
 */
export function cachedGet(url, params, onData) {
  const key = keyFor(url, params)

  if (cache.has(key)) onData(cache.get(key))

  return apiClient.get(url, params ? { params } : undefined).then((res) => {
    cache.set(key, res.data)
    onData(res.data)
    return res.data
  })
}

/** Apply an optimistic update to a cached response, so going back to the page doesn't flash the old state. */
export function updateCached(url, params, updater) {
  const key = keyFor(url, params)
  if (cache.has(key)) cache.set(key, updater(cache.get(key)))
}

export function clearCachedGets() {
  cache.clear()
}
