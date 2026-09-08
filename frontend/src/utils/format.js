export function formatMoney(value) {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat('ca-ES', { maximumFractionDigits: 0 }).format(value) + ' €'
}

export function formatMoneyCompact(value) {
  if (value === null || value === undefined) return '—'
  const abs = Math.abs(value)
  if (abs >= 1_000_000) return (value / 1_000_000).toFixed(2).replace(/\.00$/, '') + 'M €'
  if (abs >= 1_000) return (value / 1_000).toFixed(0) + 'k €'
  return formatMoney(value)
}

export function formatSignedMoney(value) {
  if (value === null || value === undefined) return '—'
  const sign = value > 0 ? '+' : ''
  return sign + formatMoneyCompact(value)
}

export function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ca-ES', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(
    new Date(value),
  )
}

/** "220,2 M€" — the primary money format used across stat cards and action cards. */
export function formatMoneyM(value) {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value / 1_000_000) + ' M€'
}

/** Signed compact delta: "+2,4 M€" above 1M, "+160k" / "-180k" below. */
export function formatDelta(value) {
  if (value === null || value === undefined) return null
  const abs = Math.abs(value)
  const sign = value >= 0 ? '+' : '-'
  if (abs >= 1_000_000) {
    return sign + new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(abs / 1_000_000) + ' M€'
  }
  return sign + Math.round(abs / 1000) + 'k'
}

export function formatPercent(value, { showSign = true } = {}) {
  if (value === null || value === undefined) return '—'
  const sign = showSign && value > 0 ? '+' : ''
  return sign + new Intl.NumberFormat('ca-ES', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value) + '%'
}

/** "3 h 12 min" countdown label, or null once the deadline has passed. */
export function formatCountdown(value) {
  if (!value) return null
  const diffMs = new Date(value).getTime() - Date.now()
  if (diffMs <= 0) return null
  const minutes = Math.floor(diffMs / 60000)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)
  if (days > 0) return `${days} ${days === 1 ? 'dia' : 'dies'}`
  if (hours > 0) return `${hours} h ${minutes % 60} min`
  return `${minutes % 60} min`
}

export function initials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase()
}

const ORDINAL_SUFFIXES = { 1: 'r', 2: 'n', 3: 'r', 4: 't' }

export function formatOrdinal(n) {
  if (n === null || n === undefined) return '—'
  return n + (ORDINAL_SUFFIXES[n] || 'è')
}

export function greeting(date = new Date()) {
  const hour = date.getHours()
  if (hour < 6) return 'Bona nit'
  if (hour < 13) return 'Bon dia'
  if (hour < 20) return 'Bona tarda'
  return 'Bon vespre'
}

export function formatFullDate(date = new Date()) {
  const text = new Intl.DateTimeFormat('ca-ES', { weekday: 'long', day: 'numeric', month: 'long' }).format(date)
  return text.charAt(0).toUpperCase() + text.slice(1)
}

/** Minutes-ago/hours-ago label for the sync-status chip. */
export function formatRelativeTime(value) {
  if (!value) return null
  const diffMs = Date.now() - new Date(value).getTime()
  const minutes = Math.round(diffMs / 60000)
  if (minutes < 1) return 'ara mateix'
  if (minutes < 60) return `fa ${minutes} min`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `fa ${hours} ${hours === 1 ? 'hora' : 'hores'}`
  const days = Math.round(hours / 24)
  return `fa ${days} ${days === 1 ? 'dia' : 'dies'}`
}

export const ACTION_LABELS = {
  BUY: 'COMPRAR',
  SELL: 'VENDRE',
  HOLD: 'MANTENIR',
  MARKET_LIST: 'POSAR AL MERCAT',
  RAISE_BID: 'PUJAR OFERTA',
  WITHDRAW_BID: 'RETIRAR OFERTA',
  PAY_CLAUSE: 'PAGAR CLÀUSULA',
  DO_NOT_PAY_CLAUSE: 'NO PAGAR CLÀUSULA',
  LOCK_CLAUSE: 'BLINDAR',
  UNLOCK_CLAUSE: 'NO BLINDAR',
  // economicRecommendation tier from ClauseEconomicAnalysisService (Clàusules) —
  // a 4-tier scale, distinct from (though sharing PAY_CLAUSE with) the app's
  // general sporting recommendations above.
  CONSIDER: 'A CONSIDERAR',
  WAIT: 'ESPERAR',
  DO_NOT_PAY: 'NO PAGAR',
}

export const ACTION_COLORS = {
  BUY: 'buy',
  SELL: 'sell',
  HOLD: 'hold',
  MARKET_LIST: 'trading',
  RAISE_BID: 'buy',
  WITHDRAW_BID: 'sell',
  PAY_CLAUSE: 'clause',
  DO_NOT_PAY_CLAUSE: 'hold',
  LOCK_CLAUSE: 'clause',
  UNLOCK_CLAUSE: 'hold',
  CONSIDER: 'buy',
  WAIT: 'trading',
  DO_NOT_PAY: 'sell',
}

export const POSITION_LABELS = {
  GK: 'POR',
  DF: 'DEF',
  MF: 'MIG',
  FW: 'DAV',
}

export const PLAYER_TYPE_LABEL = { ESPORTIU: 'Esportiu', TRADING: 'Trading', HIBRID: 'Híbrid' }

/**
 * A lightweight, transparent heuristic over real signals — not a separate
 * backend concept. "Trading" = rising value without much sporting output (a
 * flip candidate); "Esportiu" = solid real performer; "Híbrid" = both.
 */
export function classifyPlayerType(trendClassification, averagePoints) {
  const rising = trendClassification === 'ALCISTA' || trendClassification === 'MOLT_ALCISTA'
  const strongPerformer = averagePoints >= 5
  if (rising && strongPerformer) return 'HIBRID'
  if (rising) return 'TRADING'
  return 'ESPORTIU'
}

export const ACTION_SUBTITLES = {
  BUY: 'Oportunitat de compra al mercat',
  SELL: 'Ven abans que continuï baixant',
  MARKET_LIST: 'Candidat a posar al mercat',
  RAISE_BID: 'Val la pena pujar la teva oferta',
  WITHDRAW_BID: 'Retira l’oferta activa',
  PAY_CLAUSE: 'Oportunitat de clàusula',
  DO_NOT_PAY_CLAUSE: 'La clàusula no surt a compte',
  LOCK_CLAUSE: 'Blinda’l abans que te’l prenguin',
  UNLOCK_CLAUSE: 'El blindatge ja no cal',
}

export const ACTION_CTA_LABELS = {
  BUY: 'Veure jugador',
  SELL: 'Veure anàlisi',
  MARKET_LIST: 'Veure jugador',
  RAISE_BID: 'Veure oferta',
  WITHDRAW_BID: 'Veure oferta',
  PAY_CLAUSE: 'Analitzar clàusula',
  DO_NOT_PAY_CLAUSE: 'Analitzar clàusula',
  LOCK_CLAUSE: 'Veure jugador',
  UNLOCK_CLAUSE: 'Veure jugador',
}

export const URGENT_ACTIONS = ['SELL', 'WITHDRAW_BID', 'DO_NOT_PAY_CLAUSE', 'UNLOCK_CLAUSE']

export const PRIORITY_LABELS = {
  CRITICAL: 'CRÍTICA',
  HIGH: 'ALTA',
  MEDIUM: 'MITJANA',
  LOW: 'BAIXA',
}
