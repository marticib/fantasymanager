import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import apiClient from '../api/client'
import { cachedGet } from '../api/cachedGet'
import ActionModal from '../components/ActionModal'
import InfoTip from '../components/InfoTip'
import { PageSkeleton, EmptyState, ErrorState } from '../components/PageState'
import { Tag } from 'lucide-react'
import { DATA_CHANGED, useAppEvent } from '../lib/appEvents'
import { formatMoney, formatPercent, formatCountdown, POSITION_LABELS, initials } from '../utils/format'

const OFFER_CHECK_FAILED_HELP =
  "LaLiga diu que hi ha una oferta però ara mateix no s'ha pogut consultar l'import. Torna-ho a provar d'aquí uns minuts."

function profitClass(value) {
  if (value === null || value === undefined) return 'text-muted-foreground'
  if (value > 0) return 'text-bull'
  if (value < 0) return 'text-bear'
  return 'text-muted-foreground'
}

// Full precision, signed — formatMoneyCompact/formatDelta round to k/M, which
// is exactly what hid that these offers were real (if close-looking) numbers.
function formatSignedFull(value) {
  if (value === null || value === undefined) return '—'
  return (value > 0 ? '+' : '') + formatMoney(value)
}

function offerSummaryLabel(row) {
  if (row.marketOffer !== null) return formatMoney(row.marketOffer)
  if (row.offersCheckFailed) return 'No s\'ha pogut comprovar'
  return 'Sense ofertes'
}

// Confirmed live: LaLiga auto-bids close to market value on a listed player
// nobody else has bid on yet — not a rival manager's real offer, even though
// it goes through the same accept/reject flow. Must never look like someone
// actually wants to buy when it's just this standing market bid.
function offerSourceLabel(isFromMarket) {
  if (isFromMarket === true) return 'oferta estàndard del mercat, no d\'un mànager'
  if (isFromMarket === false) return 'oferta d\'un altre mànager'
  return null
}

function OfferSummary({ row }) {
  return (
    <>
      <span className={`inline-flex items-center gap-1 font-semibold ${row.marketOffer !== null ? '' : 'text-muted-foreground'}`}>
        {offerSummaryLabel(row)}
        {row.offersCheckFailed && <InfoTip>{OFFER_CHECK_FAILED_HELP}</InfoTip>}
      </span>
      {offerSourceLabel(row.marketOfferIsFromMarket) && (
        <p className={`text-[11px] ${row.marketOfferIsFromMarket ? 'text-muted-foreground' : 'font-semibold text-bull'}`}>
          {offerSourceLabel(row.marketOfferIsFromMarket)}
        </p>
      )}
    </>
  )
}

function OffersList({ row, onAccept, onReject }) {
  if (!row.offers || row.offers.length === 0) return null

  return (
    <div className="mt-2 space-y-1.5">
      {row.offers.map((offer) => (
        <div key={offer.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface-raised px-2.5 py-1.5">
          <div className="text-xs">
            <span className="num font-semibold">{formatMoney(offer.money)}</span>
            {formatCountdown(offer.expiresAt) && <span className="ml-1.5 text-muted-foreground">caduca en {formatCountdown(offer.expiresAt)}</span>}
            {offerSourceLabel(offer.isFromMarket) && (
              <span className={`ml-1.5 ${offer.isFromMarket ? 'text-muted-foreground' : 'font-semibold text-bull'}`}>
                · {offerSourceLabel(offer.isFromMarket)}
              </span>
            )}
          </div>
          <div className="flex gap-1.5">
            <button
              onClick={() => onAccept(row, offer)}
              className="min-h-9 rounded-md bg-bull/20 px-3 py-1 text-xs font-semibold text-bull hover:bg-bull/30 "
            >
              Acceptar
            </button>
            <button
              onClick={() => onReject(row, offer)}
              className="min-h-9 rounded-md border border-border px-3 py-1 text-xs font-semibold text-muted-foreground hover:text-bear disabled:opacity-50"
            >
              Rebutjar
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}

function SaleCard({ row, onAccept, onReject }) {
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
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Preu de venda</p>
          <p className="num text-lg font-bold">{formatMoney(row.salePrice)}</p>
        </div>
        <div className="text-right">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Oferta del mercat</p>
          <div className="num text-sm">
            <OfferSummary row={row} />
          </div>
        </div>
      </div>

      <OffersList row={row} onAccept={onAccept} onReject={onReject} />

      <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs text-muted-foreground">
        <span>Comprat per {row.purchasePrice !== null ? formatMoney(row.purchasePrice) : '—'}</span>
        <span>{formatCountdown(row.expiresAt) ? `Caduca en ${formatCountdown(row.expiresAt)}` : '—'}</span>
      </div>

      <div className="mt-2 flex items-center justify-between text-xs">
        <span className="text-muted-foreground">Guany/Pèrdua</span>
        <span className={`num font-semibold ${profitClass(row.profit)}`}>
          {row.profit !== null ? `${formatSignedFull(row.profit)} (${formatPercent(row.profitPct)})` : '—'}
        </span>
      </div>
    </div>
  )
}

export default function Sales() {
  const [payload, setPayload] = useState(null)
  const [error, setError] = useState('')
  const [pendingAction, setPendingAction] = useState(null)

  const load = () => {
    setError('')
    cachedGet('/team/sales', null, setPayload).catch((err) => setError(err.response?.data?.message || "No s'han pogut carregar les vendes."))
  }

  useEffect(load, [])
  useAppEvent(DATA_CHANGED, load)

  const handleModalClose = (wasSuccess) => {
    setPendingAction(null)
    if (wasSuccess) load()
  }

  const toggleAutoList = () => {
    const enabling = !payload.autoListSquad
    setPendingAction({
      title: enabling ? 'Posar tot l\'equip a la venda' : 'Desactivar la venda automàtica',
      description: enabling ? (
        <>
          Es posaran a la venda <strong className="text-foreground">tots</strong> els jugadors del teu equip que encara no hi siguin, cadascun pel seu
          valor de mercat actual. A partir d'ara, qualsevol jugador nou que fitxis també es posarà a la venda automàticament. Es veurà a tota la lliga.
        </>
      ) : (
        'Els jugadors que ja són al mercat s\'hi queden; només deixarem de posar-hi els fitxatges nous.'
      ),
      confirmLabel: enabling ? 'Sí, posar-los a la venda' : 'Desactivar',
      tone: enabling ? 'positive' : 'danger',
      run: async () => {
        const { data } = await apiClient.put('/team/sales/auto-list', { enabled: enabling })
        if (!enabling) return 'Venda automàtica desactivada.'
        return data.failed > 0
          ? `${data.listed} jugadors posats a la venda. ${data.failed} no s'han pogut posar: torna-ho a provar més tard.`
          : `${data.listed} jugadors posats a la venda.`
      },
    })
  }

  const handleAccept = (row, offer) => {
    setPendingAction({
      title: 'Acceptar l\'oferta',
      description: (
        <>
          Vendre <strong className="text-foreground">{row.player.name}</strong> per{' '}
          <span className="num font-semibold text-foreground">{formatMoney(offer.money)}</span>? La venda es fa ara mateix i no es pot desfer.
          {offer.isFromMarket && <span className="mt-2 block">És l'oferta estàndard del mercat, no la d'un mànager.</span>}
        </>
      ),
      confirmLabel: 'Sí, vendre',
      tone: 'positive',
      run: async () => {
        await apiClient.post(`/team/sales/${row.id}/offers/${offer.id}/accept`)
        return `${row.player.name} venut per ${formatMoney(offer.money)}.`
      },
    })
  }

  const handleReject = (row, offer) => {
    setPendingAction({
      title: 'Rebutjar l\'oferta',
      description: (
        <>
          Rebutjar l'oferta de <span className="num font-semibold text-foreground">{formatMoney(offer.money)}</span> per{' '}
          <strong className="text-foreground">{row.player.name}</strong>? El jugador continuarà en venda.
        </>
      ),
      confirmLabel: 'Sí, rebutjar',
      tone: 'danger',
      run: async () => {
        await apiClient.post(`/team/sales/${row.id}/offers/${offer.id}/reject`)
        return `Oferta per ${row.player.name} rebutjada.`
      },
    })
  }

  if (error && !payload) return <ErrorState message={error} onRetry={load} />
  if (!payload) return <PageSkeleton cards={3} rows={4} />

  const rows = payload.players || []
  const { summary } = payload

  return (
    <div>
      <ActionModal request={pendingAction} onClose={handleModalClose} />

      <h1 className="text-2xl font-bold tracking-tight">Vendes</h1>
      <p className="mt-1 text-sm text-muted-foreground">Jugadors propis que tens en venda al mercat ara mateix.</p>

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
          className={`min-h-11 shrink-0 rounded-lg px-4 py-1.5 text-sm font-semibold disabled:opacity-50 ${
            payload.autoListSquad ? 'border border-border text-muted-foreground hover:text-bear' : 'bg-bull/20 text-bull hover:bg-bull/30'
          }`}
        >
          {payload.autoListSquad ? 'Desactivar' : 'Activar'}
        </button>
      </div>

      {rows.length > 0 && (
        <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">En venda</p>
            <p className="num mt-1 text-xl font-bold">{summary.count}</p>
          </div>
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amb oferta rebuda</p>
            <p className="num mt-1 text-xl font-bold">{summary.withOffers}</p>
          </div>
          <div className="rounded-xl border border-border bg-surface p-4">
            <p className="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Guany/Pèrdua{summary.profitKnownCount < summary.count ? ` (${summary.profitKnownCount}/${summary.count})` : ''}
              <InfoTip>
                Suma de (oferta rebuda − preu de compra) dels jugadors on coneixem les dues xifres. Entre parèntesis, de quants jugadors ho sabem.
              </InfoTip>
            </p>
            <p className={`num mt-1 text-xl font-bold ${profitClass(summary.totalProfit)}`}>
              {summary.totalProfit !== null ? formatSignedFull(summary.totalProfit) : '—'}
            </p>
          </div>
        </div>
      )}

      <details className="mt-4 rounded-xl border border-border bg-surface px-4 py-3 text-sm">
        <summary className="cursor-pointer select-none font-semibold text-muted-foreground hover:text-foreground">Com funciona aquesta pàgina?</summary>
        <ul className="mt-3 list-disc space-y-1.5 pl-5 text-xs leading-relaxed text-muted-foreground">
          <li>
            <strong className="text-foreground">Preu de venda</strong> és el que vas demanar en posar-lo al mercat. No és una oferta de ningú.
          </li>
          <li>
            <strong className="text-foreground">Oferta del mercat</strong> només surt quan hi ha una oferta real pendent. Si no n'hi ha cap, posa
            «Sense ofertes» i el guany/pèrdua queda en «—».
          </li>
          <li>
            La majoria d'ofertes són l'<strong className="text-foreground">oferta estàndard del mercat</strong>: LaLiga en fa una automàticament, a
            prop del valor, per a qualsevol jugador en venda que ningú no ha pujat. No és un mànager rival, però s'accepta o es rebutja igual.
          </li>
          <li>
            El <strong className="text-foreground">preu de compra</strong> surt de l'historial d'activitat de la lliga. Si vas fitxar el jugador abans
            que en tinguéssim registre (per exemple, a l'assignació inicial), queda en «—».
          </li>
        </ul>
      </details>

      <div className="mt-4 hidden overflow-x-auto rounded-xl border border-border bg-surface md:block">
        <table className="w-full min-w-200 text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase text-muted-foreground">
              <th className="px-4 py-3">Jugador</th>
              <th className="px-4 py-3">Pos.</th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  Valor <InfoTip>Valor de mercat actual del jugador segons LaLiga Fantasy.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  Preu de venda <InfoTip>El preu que vas demanar en posar-lo al mercat. No és una oferta de ningú.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  Oferta del mercat
                  <InfoTip>
                    Oferta real pendent. Sovint és l'oferta estàndard del mercat (automàtica, no d'un mànager). Posa «Sense ofertes» si encara no n'hi ha
                    cap.
                  </InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  Preu de compra <InfoTip>El que vas pagar per aquest jugador, segons l'historial d'activitat de la lliga.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">
                <span className="inline-flex items-center gap-1">
                  Guany/Pèrdua <InfoTip>Oferta del mercat menys preu de compra. Només surt si hi ha oferta i coneixem el preu de compra.</InfoTip>
                </span>
              </th>
              <th className="px-4 py-3">Caducitat</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-border last:border-0 hover:bg-surface-raised">
                <td className="px-4 py-3">
                  <Link to={`/players/${row.player.id}`} className="flex items-center gap-2.5 hover:text-primary">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-muted-foreground/15 text-[11px] font-bold text-muted-foreground">
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
                <td className="px-4 py-3 font-medium">{formatMoney(row.marketValue)}</td>
                <td className="px-4 py-3 text-muted-foreground">{formatMoney(row.salePrice)}</td>
                <td className="px-4 py-3">
                  <OfferSummary row={row} />
                  <OffersList row={row} onAccept={handleAccept} onReject={handleReject} />
                </td>
                <td className="px-4 py-3 text-muted-foreground">{row.purchasePrice !== null ? formatMoney(row.purchasePrice) : '—'}</td>
                <td className={`px-4 py-3 font-medium ${profitClass(row.profit)}`}>
                  {row.profit !== null ? `${formatSignedFull(row.profit)} (${formatPercent(row.profitPct)})` : '—'}
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  {formatCountdown(row.expiresAt) ? `${formatCountdown(row.expiresAt)}` : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 && (
          <EmptyState
            icon={Tag}
            className="m-4"
            title="No tens cap jugador en venda"
            message="Posa'n un a la venda des de LaLiga Fantasy o activa la venda automàtica de tot l'equip."
          />
        )}
      </div>

      <div className="mt-4 space-y-3 md:hidden">
        {rows.map((row) => (
          <SaleCard key={row.id} row={row} onAccept={handleAccept} onReject={handleReject} />
        ))}
        {rows.length === 0 && (
          <EmptyState
            icon={Tag}
            title="No tens cap jugador en venda"
            message="Posa'n un a la venda des de LaLiga Fantasy o activa la venda automàtica de tot l'equip."
          />
        )}
      </div>
    </div>
  )
}
