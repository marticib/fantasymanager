import { useState } from 'react'
import BuildXiTab from '../components/trading/BuildXiTab'
import MakeMoneyTab from '../components/trading/MakeMoneyTab'
import { HorizonFilter } from '../components/trading/TradingShared'

const TABS = [
  { key: 'xi', label: 'Construir onze' },
  { key: 'money', label: 'Guanyar diners' },
]

const FALLBACK_HORIZONS = [3, 7, 14]

export default function Trading() {
  const [tab, setTab] = useState('xi')
  const [horizon, setHorizon] = useState(14)
  const [meta, setMeta] = useState(null)

  const horizons = meta?.horizons || FALLBACK_HORIZONS

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Trading</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Centre d’estratègia econòmica: només diners i valor de mercat, mai rendiment esportiu. Valor i tendència de FútbolFantasy; estat de la lliga de LaLiga Fantasy.
        </p>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-xl border border-border bg-surface p-1" role="tablist">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              role="tab"
              aria-selected={tab === t.key}
              onClick={() => setTab(t.key)}
              className={`rounded-lg px-4 py-1.5 text-sm font-semibold transition-colors ${
                tab === t.key ? 'bg-primary text-background' : 'text-muted-foreground hover:text-foreground'
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>
        <HorizonFilter value={horizon} options={horizons} onChange={setHorizon} />
      </div>

      {tab === 'xi' ? <BuildXiTab horizon={horizon} onMeta={setMeta} /> : <MakeMoneyTab horizon={horizon} onMeta={setMeta} />}
    </div>
  )
}
