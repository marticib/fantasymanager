import { useEffect, useState } from 'react'
import apiClient from '../api/client'
import ActionModal from './ActionModal'
import { formatMoney } from '../utils/format'

const STEP = 100_000

/**
 * Place a real bid with an amount the user can adjust: quick presets (current
 * value / recommended / maximum) plus a free, exact amount field.
 *
 * `bid` is null when closed. Shape: { playerName, marketPlayerId,
 * presets: [{ key, label, amount }], initialKey?, onPlaced?(myBid, amount) }.
 */
export default function BidDialog({ bid, onClose }) {
  const [amount, setAmount] = useState(0)

  useEffect(() => {
    if (!bid) return
    const usable = bid.presets.filter((p) => p.amount > 0)
    const initial = usable.find((p) => p.key === bid.initialKey) || usable[0]
    setAmount(initial ? Math.round(initial.amount) : 0)
  }, [bid])

  const request = bid && {
    title: `Oferta per ${bid.playerName}`,
    description: 'Tria un import o escriu-ne un d\'exacte. Es farà una puja real a LaLiga Fantasy.',
    content: <AmountField amount={amount} onChange={setAmount} presets={bid.presets} />,
    confirmLabel: amount > 0 ? `Pujar ${formatMoney(amount)}` : 'Pujar',
    confirmDisabled: !(amount > 0),
    tone: 'positive',
    run: async () => {
      const value = Math.round(amount)
      const { data } = await apiClient.post(`/market/${bid.marketPlayerId}/bid`, { amount: value })
      bid.onPlaced?.(data.myBid ?? { money: value }, value)
      return `Oferta de ${formatMoney(value)} enviada per ${bid.playerName}.`
    },
  }

  return <ActionModal request={request} onClose={onClose} />
}

function AmountField({ amount, onChange, presets }) {
  const usable = presets.filter((p) => p.amount > 0)

  return (
    <div>
      {usable.length > 0 && (
        <div className="grid grid-cols-3 gap-2">
          {usable.map((p) => {
            const active = Math.round(p.amount) === amount
            return (
              <button
                key={p.key}
                type="button"
                onClick={() => onChange(Math.round(p.amount))}
                className={`min-h-11 rounded-lg border px-2 py-1.5 text-center transition-colors ${
                  active ? 'border-bull bg-bull/15 text-bull' : 'border-border text-muted-foreground hover:border-bull/50'
                }`}
              >
                <span className="block text-[11px] font-semibold uppercase tracking-wide">{p.label}</span>
                <span className="num block text-xs font-bold text-foreground">{formatMoney(Math.round(p.amount))}</span>
              </button>
            )
          })}
        </div>
      )}

      <label className="mt-4 block text-xs font-semibold uppercase tracking-wide text-muted-foreground" htmlFor="bid-amount">
        Import exacte
      </label>
      <div className="mt-1.5 flex items-center gap-2">
        <button
          type="button"
          onClick={() => onChange(Math.max(0, amount - STEP))}
          className="min-h-11 min-w-11 rounded-lg border border-border text-sm font-semibold text-muted-foreground hover:text-foreground"
          aria-label="Baixar 100.000 €"
        >
          −
        </button>
        <div className="relative flex-1">
          <input
            id="bid-amount"
            inputMode="numeric"
            value={amount ? new Intl.NumberFormat('ca-ES').format(amount) : ''}
            onChange={(e) => onChange(Number(e.target.value.replace(/\D/g, '')) || 0)}
            className="num min-h-11 w-full rounded-lg border border-border bg-background px-3 pr-8 text-right text-base font-semibold outline-none focus:border-primary"
          />
          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">€</span>
        </div>
        <button
          type="button"
          onClick={() => onChange(amount + STEP)}
          className="min-h-11 min-w-11 rounded-lg border border-border text-sm font-semibold text-muted-foreground hover:text-foreground"
          aria-label="Pujar 100.000 €"
        >
          +
        </button>
      </div>
      <p className="mt-1.5 text-xs text-muted-foreground">− i + resten o sumen 100.000 €.</p>
    </div>
  )
}
