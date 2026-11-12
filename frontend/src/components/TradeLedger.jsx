import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { cachedGet } from '../api/cachedGet'
import { BlockSkeleton } from './PageState'
import InfoTip from './InfoTip'
import CountUp from './CountUp'
import { formatDelta, formatMoney, formatMoneyM, formatPercent, initials } from '../utils/format'

const tone = (v) => (v == null ? 'text-muted-foreground' : v > 0 ? 'text-bull' : v < 0 ? 'text-bear' : 'text-muted-foreground')
const date = (iso) => (iso ? new Intl.DateTimeFormat('ca-ES', { day: 'numeric', month: 'short' }).format(new Date(iso)) : '—')

function Player({ p }) {
  if (!p) return <span className="text-muted-foreground">—</span>
  return (
    <Link to={`/players/${p.id}`} className="flex min-w-0 items-center gap-2.5 hover:text-primary">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-surface-raised text-[11px] font-bold text-muted-foreground">
        {p.imageUrl ? <img src={p.imageUrl} alt="" className="h-full w-full object-cover" /> : initials(p.name)}
      </span>
      <span className="truncate font-medium">{p.name}</span>
    </Link>
  )
}

function Stat({ label, children, sub, info }) {
  return (
    <div className="panel p-4">
      <p className="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
        {info && <InfoTip>{info}</InfoTip>}
      </p>
      <div className="num mt-1 text-xl font-bold">{children}</div>
      {sub && <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>}
    </div>
  )
}

/** "El teu balanç": closed trades with their result, matchday rewards and paper gains on the players you hold. */
export default function TradeLedger() {
  const [data, setData] = useState(null)
  const [tab, setTab] = useState('closed')
  const [showAll, setShowAll] = useState(false)

  useEffect(() => {
    cachedGet('/history/trades', null, setData).catch(() => setData(false))
  }, [])

  if (data === false) return null

  const s = data?.summary
  const closed = data ? (showAll ? data.closed : data.closed.slice(0, 10)) : []
  const unrealized = data?.open.reduce((sum, p) => sum + (p.unrealized ?? 0), 0)

  return (
    <section className="mt-6">
      <h2 className="text-xl font-bold">El teu balanç</h2>
      <p className="mt-1 text-sm text-muted-foreground">Què has guanyat comprant i venent jugadors aquesta temporada, segons l'historial de la lliga.</p>

      {!data ? (
        <div className="panel mt-4">
          <BlockSkeleton rows={4} />
        </div>
      ) : (
        <>
          <div className="stagger mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat
              label="Resultat de les vendes"
              sub={`${s.wins} amb guany · ${s.losses} amb pèrdua`}
              info={
                s.unknownCost > 0
                  ? `${s.unknownCost} vendes són de jugadors de la plantilla inicial: no hi ha preu de compra i no compten al resultat.`
                  : 'Suma de (preu de venda − preu de compra) de cada jugador que has venut.'
              }
            >
              <span className={tone(s.realizedProfit)}>{s.realizedProfit == null ? '—' : <CountUp value={s.realizedProfit} format={formatDelta} />}</span>
            </Stat>
            <Stat label="Premis de jornada" sub={`${s.rewardsCount} jornades cobrades`}>
              <span className="text-bull">
                <CountUp value={s.rewards} format={formatMoneyM} />
              </span>
            </Stat>
            <Stat label="Guany latent" sub="Jugadors que tens: valor actual − el que vas pagar" info="Només dels jugadors dels quals coneixem el preu de compra.">
              <span className={tone(unrealized)}>
                <CountUp value={unrealized} format={formatDelta} />
              </span>
            </Stat>
            <Stat label="Millor operació" sub={s.best ? `${s.best.player?.name} · ${formatPercent(s.best.profitPct)}` : null}>
              <span className="text-bull">{s.best ? formatDelta(s.best.profit) : '—'}</span>
            </Stat>
          </div>

          <div className="mt-4 inline-flex gap-1 rounded-full border border-border bg-surface p-1" role="tablist">
            {[
              ['closed', `Vendes (${data.closed.length})`],
              ['open', `Plantilla actual (${data.open.length})`],
            ].map(([key, label]) => (
              <button
                key={key}
                role="tab"
                aria-selected={tab === key}
                onClick={() => setTab(key)}
                className={`min-h-9 rounded-full px-4 text-sm font-semibold ${tab === key ? 'bg-primary text-background' : 'text-muted-foreground hover:text-foreground'}`}
              >
                {label}
              </button>
            ))}
          </div>

          <div key={tab} className="panel mt-3 overflow-x-auto">
            {tab === 'closed' ? (
              <table className="w-full min-w-180 text-sm">
                <thead>
                  <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
                    <th className="px-4 py-3">Jugador</th>
                    <th className="px-4 py-3 text-right">Comprat</th>
                    <th className="px-4 py-3 text-right">Venut</th>
                    <th className="px-4 py-3">A qui</th>
                    <th className="px-4 py-3 text-right">Dies</th>
                    <th className="px-4 py-3 text-right">Resultat</th>
                  </tr>
                </thead>
                <tbody className="stagger-rows">
                  {closed.map((c, i) => (
                    <tr key={`${c.soldAt}-${i}`} className="border-b border-border last:border-0">
                      <td className="max-w-56 px-4 py-2.5">
                        <Player p={c.player} />
                      </td>
                      <td className="px-4 py-2.5 text-right">
                        <span className="num">{c.boughtFor != null ? formatMoney(c.boughtFor) : '—'}</span>
                        <span className="block text-[11px] text-muted-foreground">{c.boughtFor != null ? date(c.boughtAt) : 'plantilla inicial'}</span>
                      </td>
                      <td className="px-4 py-2.5 text-right">
                        <span className="num">{formatMoney(c.soldFor)}</span>
                        <span className="block text-[11px] text-muted-foreground">{date(c.soldAt)}</span>
                      </td>
                      <td className="px-4 py-2.5 text-muted-foreground">{c.soldTo || 'Mercat'}</td>
                      <td className="num px-4 py-2.5 text-right text-muted-foreground">{c.daysHeld ?? '—'}</td>
                      <td className={`num px-4 py-2.5 text-right font-semibold ${tone(c.profit)}`}>
                        {c.profit != null ? formatDelta(c.profit) : '—'}
                        {c.profitPct != null && <span className="block text-[11px] font-normal text-muted-foreground">{formatPercent(c.profitPct)}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <table className="w-full min-w-160 text-sm">
                <thead>
                  <tr className="border-b border-border bg-surface-raised/40 text-left text-[11px] uppercase text-muted-foreground">
                    <th className="px-4 py-3">Jugador</th>
                    <th className="px-4 py-3 text-right">Pagat</th>
                    <th className="px-4 py-3 text-right">Val ara</th>
                    <th className="px-4 py-3 text-right">Guany latent</th>
                  </tr>
                </thead>
                <tbody className="stagger-rows">
                  {data.open.map((p) => (
                    <tr key={p.player.id} className="border-b border-border last:border-0">
                      <td className="max-w-56 px-4 py-2.5">
                        <Player p={p.player} />
                      </td>
                      <td className="px-4 py-2.5 text-right">
                        <span className="num">{p.boughtFor != null ? formatMoney(p.boughtFor) : '—'}</span>
                        <span className="block text-[11px] text-muted-foreground">{p.boughtFor != null ? date(p.boughtAt) : 'plantilla inicial'}</span>
                      </td>
                      <td className="num px-4 py-2.5 text-right">{formatMoney(p.value)}</td>
                      <td className={`num px-4 py-2.5 text-right font-semibold ${tone(p.unrealized)}`}>{p.unrealized != null ? formatDelta(p.unrealized) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
          {tab === 'closed' && data.closed.length > 10 && (
            <button onClick={() => setShowAll((v) => !v)} className="mt-3 text-sm font-semibold text-primary hover:underline">
              {showAll ? 'Mostrar-ne menys' : `Veure totes les vendes (${data.closed.length})`}
            </button>
          )}
        </>
      )}
    </section>
  )
}
