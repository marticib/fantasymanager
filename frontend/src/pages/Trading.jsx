import { useEffect, useState } from 'react'
import apiClient from '../api/client'
import { formatMoney, formatSignedMoney } from '../utils/format'

export default function Trading() {
  const [rows, setRows] = useState(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    apiClient
      .get('/trading/opportunities')
      .then((res) => {
        setRows(res.data.data)
        setMessage(res.data.message || '')
      })
      .catch((err) => setError(err.response?.data?.message || 'Error carregant oportunitats de trading.'))
  }, [])

  if (error) return <p className="text-bear">{error}</p>
  if (!rows) return <p className="text-muted-foreground">Carregant…</p>

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Trading</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        Jugadors amb tendència alcista on comprar i revendre pot generar benefici, no rendiment esportiu.
      </p>
      {message && <p className="mt-3 text-sm text-muted-foreground">{message}</p>}

      <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        {rows.map((op, i) => (
          <div key={i} className="rounded-2xl border border-warn/30 bg-warn/5 p-5">
            <span className="rounded-md bg-warn/15 px-2 py-1 text-xs font-bold text-warn">TRADING</span>
            <p className="mt-3 text-lg font-bold">{op.player.name}</p>
            <p className="text-xs text-muted-foreground">{op.player.position}</p>
            <div className="mt-3 space-y-1 text-sm">
              <p>Comprar per ~{formatMoney(op.buyPrice)}</p>
              <p className="text-bull">Tendència: {formatSignedMoney(op.dailyTrend)}/dia</p>
              <p>
                Venda estimada en {op.horizonDays} dies: <strong>{formatMoney(op.projectedValue)}</strong>
              </p>
              <p className="font-semibold text-bull">Benefici estimat: {formatSignedMoney(op.estimatedProfit)}</p>
            </div>
          </div>
        ))}
        {rows.length === 0 && !message && (
          <p className="text-sm text-muted-foreground">Cap oportunitat clara de trading ara mateix.</p>
        )}
      </div>
    </div>
  )
}
