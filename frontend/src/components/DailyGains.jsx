import ChangeBadge from './ChangeBadge'
import { formatFullDate, formatMoneyM } from '../utils/format'

/**
 * "Guany diari": what a squad gained or lost each of the last days from its
 * players' value moves (signings and sales never count). `daily` is the API's
 * RosterValueHistoryService::daily() list, oldest first. Hidden when there's
 * no day with a known change yet.
 */
export default function DailyGains({ daily, subject = 'la seva plantilla actual' }) {
  if (!daily?.some((d) => d.change !== null)) return null

  return (
    <div className="mt-6 panel p-5">
      <h2 className="font-semibold">Guany diari</h2>
      <p className="mt-1 text-xs text-muted-foreground">
        Quant ha guanyat o perdut cada dia {subject} pel canvi de valor dels jugadors (els fitxatges i vendes no compten).
      </p>
      <div className="stagger mt-3 divide-y divide-border">
        {[...daily].reverse().map((row) => (
          <div key={row.day} className="flex items-center justify-between gap-3 py-2.5 text-sm">
            <span className="capitalize text-muted-foreground">{formatFullDate(new Date(`${row.day}T12:00:00`))}</span>
            <div className="flex items-center gap-3">
              <span className="num font-medium">{formatMoneyM(row.value)}</span>
              <ChangeBadge change={row.change} changePct={row.changePct} />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
