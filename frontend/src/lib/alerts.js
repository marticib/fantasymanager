// Labels for FantasyAlert::TYPE_* (backend/app/Models/FantasyAlert.php).
export const ALERT_TYPE_LABELS = {
  OFFER_RECEIVED: { one: 'oferta nova pels teus jugadors en venda', many: 'ofertes noves pels teus jugadors en venda' },
  AUCTION_ENDED: { one: 'subhasta on havies pujat ha acabat', many: 'subhastes on havies pujat han acabat' },
  CLAUSE_ORDER: { one: 'novetat en les compres automàtiques de clàusules', many: 'novetats en les compres automàtiques de clàusules' },
  SESSION_EXPIRED: { one: 'avís: cal tornar a connectar LaLiga', many: 'avisos: cal tornar a connectar LaLiga' },
  BUY_OPPORTUNITY: { one: 'oportunitat de compra', many: 'oportunitats de compra' },
  PRICE_DROP: { one: 'baixada de preu', many: 'baixades de preu' },
  CLAUSE_OPPORTUNITY: { one: 'oportunitat de clàusula', many: 'oportunitats de clàusula' },
  MARKET_AVAILABILITY: { one: 'jugador disponible al mercat', many: 'jugadors disponibles al mercat' },
  RISK_OF_LOSS: { one: 'risc de perdre un jugador', many: 'riscos de perdre jugadors' },
}

export function alertCountLabel(type, count) {
  const labels = ALERT_TYPE_LABELS[type]
  if (!labels) return `${count} ${count === 1 ? 'avís' : 'avisos'}`
  return `${count} ${count === 1 ? labels.one : labels.many}`
}

export const SEVERITY_DOT = {
  CRITICAL: 'bg-bear',
  WARNING: 'bg-warn',
  INFO: 'bg-info',
}

// "Since your last visit": Layout stores the previous visit's time when a new
// browser session starts, so Avui can summarise what happened in between.
export const LAST_VISIT_KEY = 'fantasy_last_visit'
export const PREV_VISIT_KEY = 'fantasy_prev_visit'
