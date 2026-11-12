import apiClient from '../api/client'

// Web Push on this device: the service worker (public/sw.js) shows what the
// server sends even with the app closed. On iPhone it only works once the
// app is added to the home screen (Share → "Afegeix a la pantalla d'inici").

export const pushSupported = () => typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

export function isIos() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent)
}

export function isStandalone() {
  return window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true
}

export function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {})
  })
}

function urlBase64ToUint8Array(base64) {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)))
}

export async function currentSubscription() {
  if (!pushSupported()) return null
  const registration = await navigator.serviceWorker.ready
  return registration.pushManager.getSubscription()
}

/** Asks permission (if needed), subscribes this device and registers it with the server. */
export async function enablePush(publicKey) {
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Has de permetre les notificacions en aquest navegador.')

  const registration = await navigator.serviceWorker.ready
  let subscription
  try {
    subscription =
      (await registration.pushManager.getSubscription()) ||
      (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) }))
  } catch {
    throw new Error("Aquest navegador no ha deixat activar les notificacions (passa en finestres privades o si les tens bloquejades per a aquesta web).")
  }

  const json = subscription.toJSON()
  await apiClient.post('/notifications/push', {
    endpoint: json.endpoint,
    keys: json.keys,
    contentEncoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
  })
  return subscription
}

export async function disablePush() {
  const subscription = await currentSubscription()
  if (!subscription) return
  await apiClient.delete('/notifications/push', { data: { endpoint: subscription.endpoint } }).catch(() => {})
  await subscription.unsubscribe()
}
