import { Link } from 'react-router-dom'
import { formatMoneyM, formatPercent, formatDelta, formatCountdown, initials } from '../utils/format'
import { ArrowRightIcon, SignalIcon } from './Icons'
import InfoTip from './InfoTip'

const TYPE_META = {
  RAISE_CLAUSE_NOW: { label: 'PUJAR CLÀUSULA AVUI', color: 'clause', cta: 'Veure detall' },
  RAISE_CLAUSE_PLANNED: { label: 'PUJAR CLÀUSULA', color: 'clause', cta: 'Veure detall' },
  ACCEPT_OFFER: { label: 'ACCEPTAR OFERTA', color: 'buy', cta: 'Veure oferta' },
  SELL: { label: 'VENDRE', color: 'sell', cta: 'Veure anàlisi' },
  BUY: { label: 'OPORTUNITAT DE COMPRA', color: 'buy', cta: 'Veure detall' },
  DO_NOT_CHASE: { label: 'NO VAL LA PENA PUJAR MÉS', color: 'hold', cta: 'Veure detall' },
  PAY_CLAUSE: { label: 'PAGAR CLÀUSULA', color: 'clause', cta: 'Veure detall' },
}

const BORDER_CLASS = {
  clause: 'border-info/25 hover:border-info/45',
  buy: 'border-bull/25 hover:border-bull/45',
  sell: 'border-bear/25 hover:border-bear/45',
  hold: 'border-border hover:border-border-strong',
}

// Soft tone-colored wash along the top of the card.
const GLOW_CLASS = {
  clause: 'from-info/12',
  buy: 'from-bull/12',
  sell: 'from-bear/12',
  hold: 'from-muted-foreground/8',
}

const TEXT_CLASS = {
  clause: 'text-info',
  buy: 'text-bull',
  sell: 'text-bear',
  hold: 'text-muted-foreground',
}

const BG_CLASS = {
  clause: 'bg-info/10 text-info',
  buy: 'bg-bull/10 text-bull',
  sell: 'bg-bear/10 text-bear',
  hold: 'bg-muted-foreground/10 text-muted-foreground',
  // Stat accents passed by color name rather than action tone.
  info: 'bg-info/10 text-info',
  bull: 'bg-bull/10 text-bull',
  bear: 'bg-bear/10 text-bear',
}

function Stat({ label, value, accent, hint, info, onBid, busy }) {
  return (
    <div className={accent ? `rounded-xl p-3 ${BG_CLASS[accent]}` : 'rounded-xl bg-surface-raised/50 p-3 ring-1 ring-inset ring-border'}>
      <p className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide opacity-80">
        {label}
        {info && <InfoTip>{info}</InfoTip>}
      </p>
      <p className="mt-1 text-lg font-bold">{value}</p>
      {hint && <p className="mt-0.5 text-[11px] opacity-70">{hint}</p>}
      {onBid && (
        <button
          onClick={onBid}
          disabled={busy}
          className="mt-1.5 min-h-9 w-full rounded-md border border-current/30 py-1 text-xs font-semibold opacity-90 hover:opacity-100 disabled:opacity-50"
        >
          Pujar
        </button>
      )}
    </div>
  )
}

export default function TodayActionCard({ action, onBid, busy, onDismiss }) {
  const meta = TYPE_META[action.type] || { label: action.title || 'ACCIÓ', color: 'hold', cta: 'Veure detall' }
  const color = action.type === 'ACCEPT_OFFER' && action.recommendation !== 'TRADE_PROFIT' ? meta.color : meta.color
  const player = action.player
  const countdown = action.deadline ? formatCountdown(action.deadline) : null
  const offerAdvantage = action.amount != null && action.projectedValue != null ? action.amount - action.projectedValue : null

  return (
    <div className={`panel elevated relative flex flex-col overflow-hidden p-5 hover:-translate-y-0.5 sm:p-6 ${BORDER_CLASS[color]}`}>
      <div className={`pointer-events-none absolute inset-x-0 top-0 h-28 bg-gradient-to-b to-transparent ${GLOW_CLASS[color]}`} />
      <div className="relative flex items-center justify-between gap-2">
        <span className={`flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wide ${BG_CLASS[color]}`}>
          <span className={`h-1.5 w-1.5 rounded-full bg-current`} />
          {action.category === 'urgent' ? 'Prioritat màxima' : 'Oportunitat'}
        </span>
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <SignalIcon className="h-3.5 w-3.5" />
          {action.confidence}% confiança
        </span>
      </div>

      <p className={`relative mt-4 text-xl font-bold tracking-tight ${TEXT_CLASS[color]}`}>{action.title || meta.label}</p>

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
            <p className="truncate text-xs text-muted-foreground">{player.club || 'Club desconegut'}</p>
          </div>
          {action.mainScore != null && (
            <div className="shrink-0 text-right">
              <p className="text-lg font-bold">{Math.round(action.mainScore)}</p>
              <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Puntuació</p>
            </div>
          )}
        </div>
      )}

      <p className="mt-4 text-sm text-muted-foreground">{action.shortExplanation}</p>

      <div className="mt-4 flex-1">
        {(action.type === 'RAISE_CLAUSE_NOW' || action.type === 'RAISE_CLAUSE_PLANNED') && (
          <div className="grid grid-cols-2 gap-2">
            {countdown && <Stat label="Temps restant" value={`Falten ${countdown}`} accent={color} />}
            {action.amount != null && <Stat label="Clàusula actual" value={formatMoneyM(action.amount)} />}
            {action.metadata?.profitableTarget != null && (
              <Stat
                label="Rendible fins a"
                value={formatMoneyM(action.metadata.profitableTarget)}
                hint={action.metadata.profitableTargetCost > 0 ? `et costaria ${formatMoneyM(action.metadata.profitableTargetCost)}` : 'ja hi ets'}
                accent="bull"
              />
            )}
            {action.metadata?.antiTheftTarget != null && (
              <Stat
                label="Anti-robatori des de"
                value={formatMoneyM(action.metadata.antiTheftTarget)}
                hint={action.metadata.antiTheftTargetCost > 0 ? `et costaria ${formatMoneyM(action.metadata.antiTheftTargetCost)}` : 'ja hi ets'}
                accent="info"
              />
            )}
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
            <Stat
              label="Valor"
              value={formatMoneyM(action.currentValue)}
              onBid={onBid && action.marketPlayerId && (() => onBid(action, 'value'))}
              busy={busy}
            />
            <Stat
              label="Oferta recomanada"
              value={formatMoneyM(action.recommendedBid)}
              accent="bull"
              onBid={onBid && action.marketPlayerId && (() => onBid(action, 'recommended'))}
              busy={busy}
            />
            <Stat
              label="Màxim"
              value={formatMoneyM(action.maxBid)}
              onBid={onBid && action.marketPlayerId && (() => onBid(action, 'max'))}
              busy={busy}
            />
            {action.metadata?.expectedROI14d != null && (
              <Stat
                label="Rendiment 14 dies"
                value={formatPercent(action.metadata.expectedROI14d * 100)}
                info="Quant esperem que guanyi (o perdi) de valor aquest jugador en 14 dies respecte del que pagaries."
              />
            )}
          </div>
        )}

        {action.type === 'DO_NOT_CHASE' && (
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Màxim" value={formatMoneyM(action.maxBid)} />
            {action.metadata?.estimatedWinningBid != null && (
              <Stat label="Oferta estimada" value={formatMoneyM(action.metadata.estimatedWinningBid)} accent="bear" />
            )}
          </div>
        )}

        {action.type === 'PAY_CLAUSE' && (
          <div className="grid grid-cols-2 gap-2">
            <Stat label="Clàusula" value={formatMoneyM(action.amount)} accent="info" />
            <Stat label="Valor" value={formatMoneyM(action.currentValue)} />
            {action.metadata?.ownerTeamName && <Stat label="Propietari" value={action.metadata.ownerTeamName} />}
            {action.metadata?.roi14d != null && (
              <Stat
                label="Rendiment 14 dies"
                value={formatPercent(action.metadata.roi14d * 100)}
                info="Quant esperem que guanyi (o perdi) de valor aquest jugador en 14 dies respecte del que pagaries."
              />
            )}
          </div>
        )}
      </div>

      <Link
        to={player ? `/players/${player.id}` : '#'}
        className={`mt-5 flex min-h-11 items-center justify-center gap-1.5 rounded-lg py-2 text-sm font-semibold ${BG_CLASS[color]} hover:opacity-80`}
      >
        {meta.cta}
        <ArrowRightIcon className="h-4 w-4" />
      </Link>
      {onDismiss && (
        <div className="mt-2 flex gap-2">
          <button
            onClick={() => onDismiss('done')}
            className="min-h-10 flex-1 rounded-lg border border-border text-xs font-semibold text-muted-foreground hover:border-bull/50 hover:text-bull"
          >
            ✓ Fet
          </button>
          <button
            onClick={() => onDismiss('ignored')}
            className="min-h-10 flex-1 rounded-lg border border-border text-xs font-semibold text-muted-foreground hover:text-foreground"
          >
            Ignorar per avui
          </button>
        </div>
      )}
    </div>
  )
}
