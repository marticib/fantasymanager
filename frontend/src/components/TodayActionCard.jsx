import { Link } from 'react-router-dom'
import { formatMoneyM, formatPercent, formatDelta, formatCountdown, initials } from '../utils/format'
import { ArrowRightIcon, SignalIcon } from './Icons'

const TYPE_META = {
  RAISE_CLAUSE_NOW: { label: 'PUJAR CLÀUSULA AVUI', color: 'clause', cta: 'Veure detall' },
  RAISE_CLAUSE_PLANNED: { label: 'PUJAR CLÀUSULA', color: 'clause', cta: 'Veure detall' },
  ACCEPT_OFFER: { label: 'ACCEPTAR OFERTA', color: 'buy', cta: 'Veure oferta' },
  SELL: { label: 'VENDRE', color: 'sell', cta: 'Veure anàlisi' },
  BUY: { label: 'OPORTUNITAT DE COMPRA', color: 'buy', cta: 'Veure detall' },
  DO_NOT_CHASE: { label: 'NO PERSEGUIR', color: 'hold', cta: 'Veure detall' },
  PAY_CLAUSE: { label: 'PAGAR CLÀUSULA', color: 'clause', cta: 'Veure detall' },
}

const BORDER_CLASS = {
  clause: 'border-clause/30',
  buy: 'border-buy/30',
  sell: 'border-sell/30',
  hold: 'border-border',
}

const TEXT_CLASS = {
  clause: 'text-clause',
  buy: 'text-buy',
  sell: 'text-sell',
  hold: 'text-text-muted',
}

const BG_CLASS = {
  clause: 'bg-clause/10 text-clause',
  buy: 'bg-buy/10 text-buy',
  sell: 'bg-sell/10 text-sell',
  hold: 'bg-hold/10 text-hold',
}

function Stat({ label, value, accent }) {
  return (
    <div className={accent ? `rounded-xl p-3 ${BG_CLASS[accent]}` : 'rounded-xl border border-border p-3'}>
      <p className="text-[11px] font-semibold uppercase tracking-wide opacity-80">{label}</p>
      <p className="mt-1 text-lg font-bold">{value}</p>
    </div>
  )
}

export default function TodayActionCard({ action }) {
  const meta = TYPE_META[action.type] || { label: action.title || 'ACCIÓ', color: 'hold', cta: 'Veure detall' }
  const color = action.type === 'ACCEPT_OFFER' && action.recommendation !== 'TRADE_PROFIT' ? meta.color : meta.color
  const player = action.player
  const countdown = action.deadline ? formatCountdown(action.deadline) : null
  const offerAdvantage = action.amount != null && action.projectedValue != null ? action.amount - action.projectedValue : null

  return (
    <div className={`flex flex-col rounded-2xl border bg-surface p-6 ${BORDER_CLASS[color]}`}>
      <div className="flex items-center justify-between gap-2">
        <span className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide ${BG_CLASS[color]}`}>
          <span className={`h-1.5 w-1.5 rounded-full bg-current`} />
          {action.category === 'urgent' ? 'Prioritat màxima' : 'Oportunitat'}
        </span>
        <span className="flex items-center gap-1.5 text-xs text-text-muted">
          <SignalIcon className="h-3.5 w-3.5" />
          {action.confidence}% confiança
        </span>
      </div>

      <p className={`mt-4 text-xl font-extrabold tracking-tight ${TEXT_CLASS[color]}`}>{action.title || meta.label}</p>

      {player && (
        <div className="mt-4 flex items-center gap-3">
          <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold ${BG_CLASS[color]}`}>
            {player.imageUrl ? (
              <img src={player.imageUrl} alt="" className="h-full w-full rounded-full object-cover" />
            ) : (
              initials(player.name)
            )}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate font-semibold">{player.name}</p>
            <p className="truncate text-xs text-text-muted">{player.club || 'Club desconegut'}</p>
          </div>
          {action.mainScore != null && (
            <div className="shrink-0 text-right">
              <p className="text-lg font-bold">{Math.round(action.mainScore)}</p>
              <p className="text-[10px] uppercase tracking-wide text-text-muted">Score</p>
            </div>
          )}
        </div>
      )}

      <p className="mt-4 text-sm text-text-muted">{action.shortExplanation}</p>

      <div className="mt-4 flex-1">
        {(action.type === 'RAISE_CLAUSE_NOW' || action.type === 'RAISE_CLAUSE_PLANNED') && (
          <div className="grid grid-cols-2 gap-2">
            {countdown && <Stat label="Temps restant" value={`Falten ${countdown}`} accent={color} />}
            {action.amount != null && <Stat label="Cost" value={formatMoneyM(action.amount)} />}
          </div>
        )}

        {(action.type === 'SELL' || action.type === 'ACCEPT_OFFER') && (
          <div className="grid grid-cols-2 gap-2">
            {action.amount != null && <Stat label="Oferta" value={formatMoneyM(action.amount)} accent={color} />}
            {action.currentValue != null && <Stat label="Valor" value={formatMoneyM(action.currentValue)} />}
            {action.projectedValue != null && <Stat label="Valor projectat 7d" value={formatMoneyM(action.projectedValue)} />}
            {offerAdvantage != null && (
              <Stat label="Avantatge oferta" value={formatDelta(offerAdvantage)} accent={offerAdvantage >= 0 ? 'buy' : 'sell'} />
            )}
          </div>
        )}

        {action.type === 'BUY' && (
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Valor" value={formatMoneyM(action.currentValue)} />
            <Stat label="Oferta recomanada" value={formatMoneyM(action.recommendedBid)} accent="buy" />
            <Stat label="Màxim" value={formatMoneyM(action.maxBid)} />
            {action.metadata?.expectedROI14d != null && (
              <Stat label="ROI 14d" value={formatPercent(action.metadata.expectedROI14d * 100)} />
            )}
          </div>
        )}

        {action.type === 'DO_NOT_CHASE' && (
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Màxim" value={formatMoneyM(action.maxBid)} />
            {action.metadata?.estimatedWinningBid != null && (
              <Stat label="Oferta estimada" value={formatMoneyM(action.metadata.estimatedWinningBid)} accent="sell" />
            )}
          </div>
        )}

        {action.type === 'PAY_CLAUSE' && (
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Clàusula" value={formatMoneyM(action.amount)} accent="clause" />
            <Stat label="Valor" value={formatMoneyM(action.currentValue)} />
            {action.metadata?.ownerTeamName && <Stat label="Propietari" value={action.metadata.ownerTeamName} />}
            {action.metadata?.roi14d != null && <Stat label="ROI 14d" value={formatPercent(action.metadata.roi14d * 100)} />}
          </div>
        )}
      </div>

      <Link
        to={player ? `/players/${player.id}` : '#'}
        className={`mt-5 flex items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold ${BG_CLASS[color]} hover:opacity-80`}
      >
        {meta.cta}
        <ArrowRightIcon className="h-4 w-4" />
      </Link>
    </div>
  )
}
