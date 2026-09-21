import { useEffect, useState } from 'react'
import { formatDelta, formatMoney, formatMoneyM, formatPercent } from '../../utils/format'
import {
  ConfidenceBadge,
  DistributionBar,
  ExcludedList,
  ExternalTrend,
  FreshnessBanner,
  Metric,
  OriginBadge,
  PlayerIdentity,
  StatCard,
  StateNotice,
  WarningChips,
} from './TradingShared'
import { toneFor, useTradingData } from './tradingData'

const NOT_CHOSEN_LABELS = {
  OTHER_ROUTE_CHOSEN: 'S’ha triat l’altra via (mercat/clàusula) del mateix jugador',
  INSUFFICIENT_CAPITAL: 'Supera el capital total desplegable',
  CAPITAL_BETTER_USED_ELSEWHERE: 'El mateix capital rendeix més en altres moviments',
}

function Movement({ move, horizon }) {
  const isSell = move.type === 'SELL'
  const price = isSell ? move.proceeds : move.cost
  return (
    <div className="panel p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <span className="num flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-raised text-xs font-bold text-muted-foreground">{move.step}</span>
          <PlayerIdentity row={move} />
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <OriginBadge kind={move.type === 'CLAUSE' ? 'CLAUSE' : move.type} />
          <ConfidenceBadge confidence={move.confidence} />
        </div>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
        <Metric
          label={isSell ? 'Ingrés estimat' : move.type === 'CLAUSE' ? 'Clàusula' : 'Puja recomanada'}
          value={formatMoney(price)}
          sub={isSell ? (move.saleKind === 'OFFER' ? 'oferta pendent' : 'estimació, no oferta') : move.type === 'BUY' ? `MaxBid ${formatMoney(move.maxBid)}` : null}
        />
        <Metric label="Valor actual" value={formatMoneyM(move.economicValue)} />
        <Metric label={`Valor ${horizon}D`} value={formatMoneyM(move.projectedValue)} />
        {isSell ? (
          <Metric label={`Vendre vs mantenir ${horizon}D`} value={formatDelta(move.sellImpact)} tone={toneFor(move.sellImpact)} />
        ) : (
          <Metric label={`Benefici ${horizon}D`} value={formatDelta(move.gain)} tone={toneFor(move.gain)} sub={move.roi !== null ? `ROI ${formatPercent(move.roi * 100)}` : null} />
        )}
      </div>

      <div className="mt-3 border-t border-border pt-3">
        <ExternalTrend external={move.external} />
        <p className="mt-1.5 text-xs text-muted-foreground">{move.explanation}</p>
        <WarningChips warnings={move.warnings} />
      </div>
    </div>
  )
}

export default function MakeMoneyTab({ horizon, onMeta }) {
  const [respectReserve, setRespectReserve] = useState(true)
  const { loading, data, meta, error } = useTradingData('/trading/make-money', { horizon, respect_reserve: respectReserve ? 1 : 0 })

  useEffect(() => {
    if (meta) onMeta(meta)
  }, [meta, onMeta])

  return (
    <div className="space-y-4">
      <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-muted-foreground">
        <input type="checkbox" checked={respectReserve} onChange={(e) => setRespectReserve(e.target.checked)} className="h-4 w-4 accent-primary" />
        Respectar la reserva mínima de caixa
      </label>

      {error && <p className="text-bear">{error}</p>}
      {loading && !data && <p className="text-muted-foreground">Optimitzant el capital…</p>}

      {data && (
        <div className={`space-y-4 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          <FreshnessBanner freshness={meta?.freshness} matching={data.matching} />

          {data.state === 'NO_LEAGUE' && <StateNotice tone="muted" title="Cap lliga activa">{data.message}</StateNotice>}

          {data.capital && (
            <>
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <StatCard
                  label="Capital disponible"
                  value={formatMoneyM(data.capital.totalDeployableCapital)}
                  sub={`Caixa ${formatMoneyM(data.capital.deployableCash)} + vendes ${formatMoneyM(data.capital.possibleCapitalFromSales)}${data.capital.salesAreEstimates ? ' (est.)' : ''}`}
                />
                <StatCard label="A invertir" value={formatMoneyM(data.summary.capitalToInvest)} sub={`${data.summary.counts.buy} mercat · ${data.summary.counts.clause} clàusules`} />
                <StatCard label="Caixa restant" value={formatMoneyM(data.summary.cashAfter)} sub={data.summary.capitalFromSales > 0 ? `Inclou ${formatMoneyM(data.summary.capitalFromSales)} de vendes` : null} />
                <StatCard
                  label={`Benefici esperat ${horizon}D`}
                  value={data.summary.expectedProfit === null ? '—' : formatDelta(data.summary.expectedProfit)}
                  tone={toneFor(data.summary.expectedProfit)}
                  sub={data.summary.sellImpact !== null ? `Vendes: ${formatDelta(data.summary.sellImpact)}` : null}
                />
                <StatCard label="ROI" value={data.summary.roi === null ? '—' : formatPercent(data.summary.roi * 100)} tone={toneFor(data.summary.roi)} sub="Sobre el capital invertit" />
                <StatCard label="Confiança" value={data.summary.confidence === null ? '—' : `${data.summary.confidence}/100`} />
              </div>

              <div className="panel grid grid-cols-2 gap-x-4 gap-y-3 p-4 sm:grid-cols-5">
                <Metric label="Caixa actual" value={formatMoney(data.capital.currentCash)} />
                <Metric label="Reserva mínima" value={data.capital.reserveRespected ? formatMoney(data.capital.minimumCashReserve) : 'No aplicada'} />
                <Metric label="Caixa desplegable" value={formatMoney(data.capital.deployableCash)} />
                <Metric label="Capital per vendes" value={formatMoney(data.capital.possibleCapitalFromSales)} sub={data.capital.salesAreEstimates ? 'estimació, no ofertes' : 'ofertes reals'} />
                <Metric label="Total desplegable" value={formatMoney(data.capital.totalDeployableCapital)} />
              </div>
            </>
          )}

          {data.state === 'KEEP_CASH' && (
            <StateNotice tone="info" title="ARA MATEIX NO COMPRARIA RES">
              {data.keepCash && <p>{data.keepCash.message}</p>}
              <p>Mantenir la caixa és, ara mateix, la millor posició a {horizon}D.</p>
            </StateNotice>
          )}

          {data.state === 'OK' && (
            <div className="panel p-4 text-sm">
              <p className="text-xs uppercase tracking-wide text-muted-foreground">Resum</p>
              <ul className="mt-2 space-y-1">
                {data.explanations.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
              {data.summary.exactBalance && <p className="mt-2 text-xs text-warn">El pla fa servir tot el capital fins a la reserva.</p>}
            </div>
          )}

          {data.summary && data.state === 'OK' && <DistributionBar distribution={data.summary.distribution} />}

          {data.movements?.length > 0 && (
            <section>
              <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">Accions recomanades, en ordre</h3>
              <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                {data.movements.map((move) => (
                  <Movement key={`${move.type}-${move.playerId}`} move={move} horizon={horizon} />
                ))}
              </div>
            </section>
          )}

          {data.holds?.length > 0 && (
            <details className="panel p-4">
              <summary className="cursor-pointer text-sm font-medium">
                {data.holds.length} jugadors propis es mantenen <span className="text-muted-foreground">(mantenir)</span>
              </summary>
              <ul className="mt-3 space-y-2 text-sm">
                {data.holds.map((h) => (
                  <li key={h.playerId} className="flex flex-wrap items-center justify-between gap-2">
                    <span className="flex items-center gap-2">
                      <OriginBadge kind="HOLD" /> {h.name}
                    </span>
                    <span className={`num text-xs ${h.gain === null ? 'text-muted-foreground' : h.gain >= 0 ? 'text-bull' : 'text-bear'}`}>
                      {h.gain === null ? 'sense dades FF' : `${formatDelta(h.gain)} a ${horizon}D`}
                    </span>
                  </li>
                ))}
              </ul>
            </details>
          )}

          {data.alternatives?.length > 0 && (
            <details className="panel p-4">
              <summary className="cursor-pointer text-sm font-medium">
                Millors alternatives no triades <span className="text-muted-foreground">({data.alternatives.length})</span>
              </summary>
              <ul className="mt-3 space-y-2 text-sm">
                {data.alternatives.map((a) => (
                  <li key={`${a.source}-${a.playerId}`} className="flex flex-wrap items-baseline justify-between gap-2">
                    <span>
                      {a.name} <span className="text-xs text-muted-foreground">({a.source === 'CLAUSE' ? 'clàusula' : 'mercat'} · {formatMoney(a.cost)} · {formatDelta(a.gain)})</span>
                    </span>
                    <span className="text-xs text-muted-foreground">{NOT_CHOSEN_LABELS[a.notChosenReason]}</span>
                  </li>
                ))}
              </ul>
            </details>
          )}

          <ExcludedList excluded={data.excluded} />
        </div>
      )}
    </div>
  )
}
