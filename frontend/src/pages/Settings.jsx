import { useEffect, useState } from 'react'
import apiClient from '../api/client'

const RULE_LABELS = {
  sell_daily_drop_threshold: 'Llindar de venda (variació diària, €)',
  sell_3d_drop_threshold: 'Llindar de venda (variació 3 dies, €)',
  buy_growth_threshold: 'Llindar de compra (pujada diària mínima, €)',
  minimum_cash_reserve: 'Reserva mínima de saldo (€)',
  maximum_bid_over_market_percentage: 'Sobre-oferta màxima sobre valor (%)',
  trading_minimum_expected_profit: 'Benefici mínim de trading (€)',
  trading_horizon_days: 'Horitzó de trading (dies)',
}

const WEIGHT_LABELS = {
  performance: 'Rendiment',
  value_efficiency: 'Valor / preu',
  market_trend: 'Tendència de mercat',
  starter_likelihood: 'Titularitat',
  calendar: 'Calendari',
  risk: 'Risc',
  squad_fit: 'Encaix de plantilla',
}

export default function Settings() {
  const [rules, setRules] = useState(null)
  const [weights, setWeights] = useState(null)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    apiClient.get('/settings').then((res) => {
      setRules(res.data.rules)
      setWeights(res.data.scoreWeights)
    })
  }, [])

  const save = async () => {
    setSaving(true)
    setMessage('')
    try {
      const { data } = await apiClient.put('/settings', { rules, scoreWeights: weights })
      setRules(data.rules)
      setWeights(data.scoreWeights)
      setMessage('Configuració desada.')
    } catch {
      setMessage('Error desant la configuració.')
    } finally {
      setSaving(false)
    }
  }

  if (!rules || !weights) return <p className="text-text-muted">Carregant…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Configuració</h1>
      <p className="mt-1 text-sm text-text-muted">Ajusta els llindars i pesos del motor de recomanacions.</p>

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section className="rounded-2xl border border-border bg-surface p-6">
          <h2 className="font-semibold">Regles</h2>
          <div className="mt-4 space-y-3">
            {Object.entries(rules).map(([key, value]) => (
              <div key={key} className="flex items-center justify-between gap-4">
                <label className="text-sm text-text-muted">{RULE_LABELS[key] || key}</label>
                <input
                  type="number"
                  value={value}
                  onChange={(e) => setRules((r) => ({ ...r, [key]: Number(e.target.value) }))}
                  className="w-32 rounded-lg border border-border bg-bg px-2 py-1 text-right text-sm outline-none focus:border-accent"
                />
              </div>
            ))}
          </div>
        </section>

        <section className="rounded-2xl border border-border bg-surface p-6">
          <h2 className="font-semibold">Pesos del Fantasy Score</h2>
          <p className="mt-1 text-xs text-text-muted">Es normalitzen automàticament a 100.</p>
          <div className="mt-4 space-y-3">
            {Object.entries(weights).map(([key, value]) => (
              <div key={key} className="flex items-center justify-between gap-4">
                <label className="text-sm text-text-muted">{WEIGHT_LABELS[key] || key}</label>
                <input
                  type="number"
                  value={Math.round(value)}
                  onChange={(e) => setWeights((w) => ({ ...w, [key]: Number(e.target.value) }))}
                  className="w-32 rounded-lg border border-border bg-bg px-2 py-1 text-right text-sm outline-none focus:border-accent"
                />
              </div>
            ))}
          </div>
        </section>
      </div>

      <button
        onClick={save}
        disabled={saving}
        className="mt-6 rounded-lg bg-accent px-5 py-2 text-sm font-semibold text-bg hover:opacity-90 disabled:opacity-50"
      >
        {saving ? 'Desant…' : 'Desar configuració'}
      </button>
      {message && <p className="mt-2 text-sm text-text-muted">{message}</p>}
    </div>
  )
}
