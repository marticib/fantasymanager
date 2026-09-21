import { useEffect, useState } from 'react'
import { formatDelta, formatMoney, formatMoneyM, formatPercent, POSITION_LABELS } from '../../utils/format'
import {
  ConfidenceBadge,
  DistributionBar,
  ExcludedList,
  ExternalTrend,
  FreshnessBanner,
  Metric,
  OriginBadge,
  Pill,
  PlayerIdentity,
  StatCard,
  StateNotice,
  WarningChips,
} from './TradingShared'
import { toneFor, useTradingData } from './tradingData'

const STRATEGIES = [
  { key: 'max_return', label: 'Màxima rendibilitat', hint: 'Gasta on més beneficia les noves compres' },
  { key: 'balanced', label: 'Equilibrat', hint: 'Igual, però conserva la reserva mínima de caixa' },
  { key: 'min_cost', label: 'Mínim cost', hint: 'L’onze vàlid més barat possible' },
]

const POSITIONS = ['GK', 'DF', 'MF', 'FW']
const MISSING_LABELS = { GK: 'porters', DF: 'defenses', MF: 'migcampistes', FW: 'davanters' }

function LineupRow({ row, horizon }) {
  const isOwn = row.origin === 'OWN'
  return (
    <div className="panel p-4">
      <div className="flex items-start justify-between gap-3">
        <PlayerIdentity row={row} />
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <OriginBadge kind={row.origin} />
          <ConfidenceBadge confidence={row.confidence} />
        </div>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
        <Metric label="Cost" value={isOwn ? '0 €' : formatMoney(row.cost)} sub={isOwn ? 'ja el tens' : row.origin === 'MARKET' ? `MaxBid ${formatMoney(row.maxBid)}` : 'clàusula'} />
        <Metric label="Valor actual" value={formatMoneyM(row.economicValue)} />
        <Metric label={`Valor ${horizon}D`} value={formatMoneyM(row.projectedValue)} />
        <Metric
          label={isOwn ? `Evolució ${horizon}D` : `Benefici ${horizon}D`}
          value={row.gain === null || row.gain === undefined ? '—' : formatDelta(row.gain)}
          tone={toneFor(row.gain)}
          sub={row.roi !== null && row.roi !== undefined ? `ROI ${formatPercent(row.roi * 100)}` : null}
        />
      </div>

      <div className="mt-3 border-t border-border pt-3">
        <ExternalTrend external={row.external} />
        <p className="mt-1.5 text-xs text-muted-foreground">{row.explanation}</p>
        <WarningChips warnings={row.warnings} />
      </div>
    </div>
  )
}

function Impossible({ data }) {
  const { impossible } = data
  return (
    <StateNotice tone="bear" title="No es pot formar un onze vàlid">
      <p>{impossible.message}</p>
      {impossible.reason === 'MISSING_POSITION' && (
        <p>
          Falta cobrir:{' '}
          {Object.entries(impossible.missing)
            .map(([pos, gap]) => `${gap} ${MISSING_LABELS[pos] || pos}`)
            .join(', ')}
          .
        </p>
      )}
      {impossible.reason === 'INSUFFICIENT_CASH' && (
        <p>
          Cost mínim per completar-lo: <strong className="num text-foreground">{formatMoney(impossible.minimumCostToComplete)}</strong> · disponible{' '}
          <strong className="num text-foreground">{formatMoney(impossible.availableBudget)}</strong> · et falten{' '}
          <strong className="num text-bear">{formatMoney(impossible.shortfall)}</strong>.
        </p>
      )}
    </StateNotice>
  )
}

export default function BuildXiTab({ horizon, onMeta }) {
  const [strategy, setStrategy] = useState('balanced')
  const { loading, data, meta, error } = useTradingData('/trading/build-xi', { strategy, horizon })

  useEffect(() => {
    if (meta) onMeta(meta)
  }, [meta, onMeta])

  const active = STRATEGIES.find((s) => s.key === strategy)

  return (
    <div className="space-y-4">
      <div>
        <div className="flex flex-wrap gap-2" role="group" aria-label="Estratègia">
          {STRATEGIES.map((s) => (
            <Pill key={s.key} active={strategy === s.key} onClick={() => setStrategy(s.key)} title={s.hint}>
              {s.label}
            </Pill>
          ))}
        </div>
        <p className="mt-2 text-xs text-muted-foreground">{active.hint}. Només compta el cost i el valor econòmic, mai el rendiment esportiu.</p>
      </div>

      {error && <p className="text-bear">{error}</p>}
      {loading && !data && <p className="text-muted-foreground">Calculant l’onze…</p>}

      {data && (
        <div className={`space-y-4 transition-opacity ${loading ? 'opacity-60' : ''}`}>
          <FreshnessBanner freshness={meta?.freshness} matching={data.matching} />

          {data.state === 'NO_LEAGUE' && <StateNotice tone="muted" title="Cap lliga activa">{data.message}</StateNotice>}
          {data.state === 'IMPOSSIBLE_XI' && <Impossible data={data} />}

          {data.state === 'ALREADY_HAVE_XI' && (
            <StateNotice tone="bull" title="Ja tens un onze vàlid">
              <p>Amb els teus jugadors ja es pot formar l’onze ({data.formation}) sense gastar res.</p>
            </StateNotice>
          )}

          {data.summary && (
            <>
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <StatCard label="Cost total" value={formatMoneyM(data.summary.acquisitionCost)} sub={`${data.summary.counts.market} mercat · ${data.summary.counts.clause} clàusules`} />
                <StatCard
                  label="Caixa final"
                  value={formatMoneyM(data.summary.cashAfter)}
                  tone={data.summary.respectsReserve ? 'default' : 'warn'}
                  sub={data.summary.respectsReserve ? `Reserva ${formatMoneyM(data.summary.minimumCashReserve)} OK` : `Sota la reserva (${formatMoneyM(data.summary.minimumCashReserve)})`}
                />
                <StatCard
                  label={`Benefici noves compres ${horizon}D`}
                  value={data.summary.newAcquisitionsProfit === null ? '—' : formatDelta(data.summary.newAcquisitionsProfit)}
                  tone={toneFor(data.summary.newAcquisitionsProfit)}
                  sub="Només adquisicions noves"
                />
                <StatCard
                  label="ROI noves compres"
                  value={data.summary.newAcquisitionsRoi === null ? '—' : formatPercent(data.summary.newAcquisitionsRoi * 100)}
                  tone={toneFor(data.summary.newAcquisitionsRoi)}
                />
                <StatCard
                  label={`Evolució propis ${horizon}D`}
                  value={data.summary.ownedEvolution === null ? '—' : formatDelta(data.summary.ownedEvolution)}
                  tone={toneFor(data.summary.ownedEvolution)}
                  sub={data.summary.ownedWithoutEvolution > 0 ? `${data.summary.ownedWithoutEvolution} sense dades FF` : 'Ja teus, no és benefici nou'}
                />
                <StatCard label="Confiança" value={data.summary.confidence === null ? '—' : `${data.summary.confidence}/100`} />
              </div>

              <div className="panel p-4 text-sm">
                <p className="text-xs uppercase tracking-wide text-muted-foreground">Resum · {data.formation}</p>
                <ul className="mt-2 space-y-1">
                  {data.explanations.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              </div>

              <DistributionBar distribution={data.summary.distribution} />
            </>
          )}

          {data.lineup?.length > 0 &&
            POSITIONS.map((pos) => {
              const rows = data.lineup.filter((r) => r.position === pos)
              if (!rows.length) return null
              return (
                <section key={pos}>
                  <h3 className="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    {POSITION_LABELS[pos]} <span className="num">({rows.length})</span>
                  </h3>
                  <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    {rows.map((row) => (
                      <LineupRow key={`${row.origin}-${row.playerId}`} row={row} horizon={horizon} />
                    ))}
                  </div>
                </section>
              )
            })}

          <ExcludedList excluded={data.excluded} />
        </div>
      )}
    </div>
  )
}
