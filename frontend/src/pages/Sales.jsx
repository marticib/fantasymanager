import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { formatMoneyM, formatDelta, formatPercent, formatCountdown, POSITION_LABELS, initials } from '../utils/format'

function profitClass(value) {
  if (value === null || value === undefined) return 'text-muted-foreground'
  if (value > 0) return 'text-bull'
  if (value < 0) return 'text-bear'
  return 'text-muted-foreground'
}

function offerSummaryLabel(row) {
  if (row.marketOffer !== null) return formatMoneyM(row.marketOffer)
  if (row.offersCheckFailed) return 'No s\'ha pogut comprovar'
  return 'Sense ofertes'
}

function OffersList({ row, busyOfferId, onAccept, onReject }) {
  if (!row.offers || row.offers.length === 0) return null

  return (
    <div className="mt-2 space-y-1.5">
      {row.offers.map((offer) => (
        <div key={offer.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface-raised px-2.5 py-1.5">
          <div className="text-xs">
            <span className="num font-semibold">{formatMoneyM(offer.money)}</span>
            {formatCountdown(offer.expiresAt) && <span className="ml-1.5 text-muted-foreground">caduca en {formatCountdown(offer.expiresAt)}</span>}
          </div>
          <div className="flex gap-1.5">
            <button
              onClick={() => onAccept(row, offer)}
              disabled={busyOfferId === offer.id}
              className="rounded-md bg-bull/20 px-2 py-1 text-xs font-semibold text-bull hover:bg-bull/30 disabled:opacity-50"
            >
              Acceptar
            </button>
            <button
              onClick={() => onReject(row, offer)}
              disabled={busyOfferId === offer.id}
              className="rounded-md border border-border px-2 py-1 text-xs font-semibold text-muted-foreground hover:text-bear disabled:opacity-50"
            >
              Rebutjar
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}

function SaleCard({ row, busyOfferId, onAccept, onReject }) {
  return (
    <div className="panel p-4">
      <Link to={`/players/${row.player.id}`} className="flex items-center gap-3 hover:opacity-80">
        <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full border border-border bg-surface-raised text-xs font-bold text-muted-foreground">
          {row.player.imageUrl ? <img src={row.player.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.player.name)}
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold">{row.player.name}</p>
          <p className="truncate text-xs text-muted-foreground">
            {row.player.club || '—'} · {POSITION_LABELS[row.player.position] || row.player.position}
          </p>
        </div>
      </Link>

      <div className="mt-3 flex items-end justify-between gap-3">
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Preu de venda</p>
          <p className="num text-xl font-bold">{formatMoneyM(row.salePrice)}</p>
        </div>
        <div className="text-right">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Oferta del mercat</p>
          <p
            className={`num text-sm font-semibold ${row.marketOffer !== null ? '' : 'text-muted-foreground'}`}
            title={row.offersCheckFailed ? 'LaLiga diu que hi ha una oferta però ara mateix no s\'ha pogut consultar l\'import — torna-ho a provar d\'aquí uns minuts' : undefined}
          >
            {offerSummaryLabel(row)}
          </p>
        </div>
      </div>

      <OffersList row={row} busyOfferId={busyOfferId} onAccept={onAccept} onReject={onReject} />

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
        <span>Comprat per {row.purchasePrice !== null ? formatMoneyM(row.purchasePrice) : '—'}</span>
        <span>{formatCountdown(row.expiresAt) ? `Caduca en ${formatCountdown(row.expiresAt)}` : '—'}</span>
      </div>

      <div className="mt-2 flex items-center justify-between text-xs">
        <span className="text-muted-foreground">Guany/Pèrdua</span>
        <span className={`num font-semibold ${profitClass(row.profit)}`}>
          {row.profit !== null ? `${formatDelta(row.profit)} (${formatPercent(row.profitPct)})` : '—'}
        </span>
      </div>
    </div>
  )
}

export default function Sales() {
  const [payload, setPayload] = useState(null)
  const [error, setError] = useState('')
  const [actionError, setActionError] = useState('')
  const [busyOfferId, setBusyOfferId] = useState(null)
  const [togglingAutoList, setTogglingAutoList] = useState(false)
  const [autoListNotice, setAutoListNotice] = useState('')

  const load = () => {
    apiClient
      .get('/team/sales')
      .then((res) => setPayload(res.data))
      .catch((err) => setError(err.response?.data?.message || 'Error carregant les vendes.'))
  }

  useEffect(load, [])

  const toggleAutoList = async () => {
    const enabling = !payload.autoListSquad
    if (enabling) {
      const ok = window.confirm(
        'Això posarà TOTS els jugadors del teu equip que encara no hi siguin a la venda al mercat, cadascun pel seu valor de mercat actual — ' +
          'i, a partir d\'ara, qualsevol jugador nou que fitxis es llistarà automàticament. Es veurà a tota la lliga. Vols activar-ho?',
      )
      if (!ok) return
    }
    setActionError('')
    setAutoListNotice('')
    setTogglingAutoList(true)
    try {
      const { data } = await apiClient.put('/team/sales/auto-list', { enabled: enabling })
      if (enabling) {
        setAutoListNotice(
          data.failed > 0
            ? `${data.listed} jugadors posats a la venda, ${data.failed} han fallat (revisa els logs).`
            : `${data.listed} jugadors posats a la venda.`,
        )
      }
      load()
    } catch (err) {
      setActionError(err.response?.data?.message || 'No s\'ha pogut canviar l\'opció.')
    } finally {
      setTogglingAutoList(false)
    }
  }

  const handleAccept = async (row, offer) => {
    if (!window.confirm(`Acceptar l'oferta de ${formatMoneyM(offer.money)} per ${row.player.name}? Es vendrà el jugador ara mateix.`)) return
    setActionError('')
    setBusyOfferId(offer.id)
    try {
      await apiClient.post(`/team/sales/${row.id}/offers/${offer.id}/accept`)
      load()
    } catch (err) {
      setActionError(err.response?.data?.message || 'No s\'ha pogut acceptar l\'oferta.')
    } finally {
      setBusyOfferId(null)
    }
  }

  const handleReject = async (row, offer) => {
    if (!window.confirm(`Rebutjar l'oferta de ${formatMoneyM(offer.money)} per ${row.player.name}?`)) return
    setActionError('')
    setBusyOfferId(offer.id)
    try {
      await apiClient.post(`/team/sales/${row.id}/offers/${offer.id}/reject`)
      load()
    } catch (err) {
      setActionError(err.response?.data?.message || 'No s\'ha pogut rebutjar l\'oferta.')
    } finally {
      setBusyOfferId(null)
    }
  }

  if (error) return <p className="text-bear">{error}</p>
  if (!payload) return <p className="text-muted-foreground">Carregant…</p>

  const rows = payload.players || []
  const { summary } = payload

  return (
    <div>
      <h1 className="text-2xl font-bold tracking-tight">Vendes</h1>
      <p className="mt-1 text-sm text-muted-foreground">Jugadors propis que tens en venda al mercat ara mateix.</p>

      {actionError && <p className="mt-3 text-sm text-bear">{actionError}</p>}
      {autoListNotice && <p className="mt-3 text-sm text-bull">{autoListNotice}</p>}

      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface p-4">
        <div>
          <p className="text-sm font-semibold">Posa automàticament tot l'equip al mercat</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {payload.autoListSquad
              ? 'Activat: cada jugador nou que fitxis es posa a la venda automàticament al seu valor de mercat.'
              : 'Desactivat: tu decideixes quins jugadors poses a la venda.'}
          </p>
        </div>
        <button
          onClick={toggleAutoList}
          disabled={togglingAutoList}
          className={`shrink-0 rounded-lg px-3 py-1.5 text-sm font-semibold disabled:opacity-50 ${
            payload.autoListSquad ? 'border border-border text-muted-foreground hover:text-bear' : 'bg-bull/20 text-bull hover:bg-bull/30'
          }`}
        >
          {payload.autoListSquad ? 'Desactivar' : 'Activar'}
        </button>
      </div>

      {rows.length > 0 && (
        <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">En venda</p>
            <p className="num mt-1 text-xl font-bold">{summary.count}</p>
          </div>
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Amb oferta rebuda</p>
            <p className="num mt-1 text-xl font-bold">{summary.withOffers}</p>
          </div>
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              Guany/Pèrdua{summary.profitKnownCount < summary.count ? ` (${summary.profitKnownCount}/${summary.count})` : ''}
            </p>
            <p className={`num mt-1 text-xl font-bold ${profitClass(summary.totalProfit)}`}>
              {summary.totalProfit !== null ? formatDelta(summary.totalProfit) : '—'}
            </p>
          </div>
        </div>
      )}

      <p className="mt-4 text-xs text-muted-foreground">
        «Preu de venda» és el que tu vas demanar en posar-lo al mercat, no una oferta de ningú. L'«Oferta del mercat» només apareix quan un
        altre mànager hi ha pujat de debò una oferta — si encara no n'hi ha cap, surt «Sense ofertes» i el guany/pèrdua es queda en «—» (no
        s'ha venut res encara, no hi ha res a calcular). El preu de compra ve de l'historial d'activitat de la lliga i només es coneix des
        que en tenim registre — si vas fitxar un jugador abans d'això (p. ex. a l'assignació inicial), també es queda en «—».
      </p>

      <div className="mt-4 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-200 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th className="px-4 py-3">Jugador</th>
              <th className="px-4 py-3">Pos.</th>
              <th className="px-4 py-3" title="Valor de mercat actual del jugador segons LaLiga Fantasy">
                Valor
              </th>
              <th className="px-4 py-3" title="Preu que tu vas demanar en posar-lo al mercat — no és una oferta de ningú">
                Preu de venda
              </th>
              <th className="px-4 py-3" title="Ofertes reals rebudes d'altres mànagers — «Sense ofertes» si encara no n'hi ha cap">
                Oferta del mercat
              </th>
              <th className="px-4 py-3" title="El que vas pagar per aquest jugador, segons l'historial d'activitat de la lliga">
                Preu de compra
              </th>
              <th className="px-4 py-3" title="Oferta del mercat menys preu de compra — nomès si hi ha una oferta real i preu de compra conegut">
                Guany/Pèrdua
              </th>
              <th className="px-4 py-3">Caducitat</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-border last:border-0 hover:bg-surface-raised">
                <td className="px-4 py-3">
                  <Link to={`/players/${row.player.id}`} className="flex items-center gap-2.5 hover:text-primary">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[10px] font-bold text-muted-foreground">
                      {row.player.imageUrl ? <img src={row.player.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(row.player.name)}
                    </div>
                    <div className="min-w-0">
                      <p className="truncate font-medium">{row.player.name}</p>
                      <p className="truncate text-xs text-muted-foreground">{row.player.club || '—'}</p>
                    </div>
                  </Link>
                </td>
                <td className="px-4 py-3">
                  <span className="rounded-md border border-border px-1.5 py-0.5 text-xs font-semibold">
                    {POSITION_LABELS[row.player.position] || row.player.position}
                  </span>
                </td>
                <td className="px-4 py-3 font-medium">{formatMoneyM(row.marketValue)}</td>
                <td className="px-4 py-3 text-muted-foreground">{formatMoneyM(row.salePrice)}</td>
                <td className="px-4 py-3">
                  <span
                    className={`font-semibold ${row.marketOffer !== null ? '' : 'text-muted-foreground'}`}
                    title={row.offersCheckFailed ? 'LaLiga diu que hi ha una oferta però ara mateix no s\'ha pogut consultar l\'import — torna-ho a provar d\'aquí uns minuts' : undefined}
                  >
                    {offerSummaryLabel(row)}
                  </span>
                  <OffersList row={row} busyOfferId={busyOfferId} onAccept={handleAccept} onReject={handleReject} />
                </td>
                <td className="px-4 py-3 text-muted-foreground">{row.purchasePrice !== null ? formatMoneyM(row.purchasePrice) : '—'}</td>
                <td className={`px-4 py-3 font-medium ${profitClass(row.profit)}`}>
                  {row.profit !== null ? `${formatDelta(row.profit)} (${formatPercent(row.profitPct)})` : '—'}
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  {formatCountdown(row.expiresAt) ? `${formatCountdown(row.expiresAt)}` : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">Ara mateix no tens cap jugador en venda al mercat.</p>}
      </div>

      <div className="mt-4 space-y-3 md:hidden">
        {rows.map((row) => (
          <SaleCard key={row.id} row={row} busyOfferId={busyOfferId} onAccept={handleAccept} onReject={handleReject} />
        ))}
        {rows.length === 0 && <p className="panel p-6 text-center text-sm text-muted-foreground">Ara mateix no tens cap jugador en venda al mercat.</p>}
      </div>
    </div>
  )
}
