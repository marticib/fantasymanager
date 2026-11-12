<?php

namespace App\Services\Trading;

use App\Models\FantasyAccount;
use App\Services\Recommendation\MarketValueProjector;

/**
 * "Guanyar diners": with the capital you really have — cash now plus what
 * selling some of your own players would free — which purchases (market bids
 * and clause payments) earn the most expected profit at the chosen horizon?
 * Deliberately blind to lineups, positions and squad size, and "buy nothing,
 * keep the cash" is a legitimate answer (state KEEP_CASH).
 *
 * The capital is never blurred: `currentCash` is what you hold, `deployableCash`
 * is that minus the reserve (when respected), `possibleCapitalFromSales` is
 * what *estimated* sales could add, `totalDeployableCapital` is the sum. Sale
 * prices are the pending offer when there is one and otherwise a labelled
 * estimate — no offer is ever invented. Selling a player is only proposed when
 * it pays for a better purchase or dodges an expected loss, and each player
 * (as a buy or a sell) appears at most once, so no capital is counted twice
 * and market/clause routes for the same player compete for the same money.
 *
 * A rival clause that is still locked today but unlocks before the horizon is
 * a real option for that horizon (pay it on the unlock day, hold until the
 * horizon): it competes for the same capital, flagged with `payableInDays`.
 * Only the days you would actually own him count — see withUnlockWindow().
 */
class MakeMoneyService
{
    public function __construct(
        private readonly TradingCandidateService $candidates,
        private readonly PortfolioOptimizer $optimizer,
        private readonly MarketValueProjector $projector,
    ) {}

    /** @return array<string, mixed> */
    public function build(FantasyAccount $account, int $horizon, bool $respectReserve = true, bool $allowSales = true): array
    {
        $pool = $this->candidates->pool($account);

        if ($pool === null) {
            return ['state' => 'NO_LEAGUE', 'horizon' => $horizon, 'movements' => [], 'message' => 'Encara no hi ha cap lliga activa.'];
        }

        return $this->plan($pool, $horizon, $respectReserve, $allowSales);
    }

    /**
     * The best plan for every horizon (3/7/14 days) off one shared candidate
     * pool, plus which horizon earns the most.
     *
     * @return array<string, mixed>
     */
    public function buildAll(FantasyAccount $account, bool $respectReserve = true, bool $allowSales = true): array
    {
        $pool = $this->candidates->pool($account);

        if ($pool === null) {
            return ['state' => 'NO_LEAGUE', 'plans' => [], 'bestHorizon' => null, 'message' => 'Encara no hi ha cap lliga activa.'];
        }

        $plans = [];
        foreach (TradingCandidateService::HORIZONS as $horizon) {
            $plans[$horizon] = $this->plan($pool, $horizon, $respectReserve, $allowSales);
        }

        $bestHorizon = null;
        $bestGain = 0;
        foreach ($plans as $horizon => $plan) {
            $gain = $plan['summary']['netExpectedGain'] ?? null;
            if ($gain !== null && $gain > $bestGain) {
                $bestGain = $gain;
                $bestHorizon = $horizon;
            }
        }

        return ['state' => 'OK', 'plans' => $plans, 'bestHorizon' => $bestHorizon];
    }

    /**
     * @param  array<string, mixed>  $pool  TradingCandidateService::pool()
     * @return array<string, mixed>
     */
    /**
     * @param  bool  $allowSales  false = only spend the cash you have; never propose selling your own players
     */
    private function plan(array $pool, int $horizon, bool $respectReserve, bool $allowSales = true): array
    {
        $cash = $pool['cash'];
        $reserve = $respectReserve ? (int) $pool['rules']['minimum_cash_reserve'] : 0;
        $deployableCash = max(0, $cash - $reserve);
        $minProfit = max(1, (int) $pool['rules']['trading_minimum_expected_profit']);

        $acquisitions = [...$pool['market'], ...array_map(fn ($c) => $this->withUnlockWindow($c, $horizon), $pool['clause'])];
        $eligible = array_values(array_filter($acquisitions, fn ($c) => $c['eligible']));
        $excluded = array_values(array_filter($acquisitions, fn ($c) => ! $c['eligible']));

        $profitable = [];
        foreach ($eligible as $c) {
            if (($c['profit'][$horizon] ?? 0) >= $minProfit) {
                $profitable[] = $c;
            } else {
                $excluded[] = array_merge($c, ['excludeReason' => 'BELOW_MIN_PROFIT']);
            }
        }

        // Own players we can price a sale for AND whose evolution is known —
        // a missing figure is never treated as "no change". None at all when
        // the user asked to keep the whole squad.
        $sellable = $allowSales
            ? array_values(array_filter($pool['own'], fn ($o) => $o['salePrice'] !== null && $o['projected'] !== null))
            : [];
        $possibleFromSales = (int) array_sum(array_column($sellable, 'salePrice'));

        $result = $this->optimizer->optimize(
            $this->buyGroups($profitable, $horizon),
            array_map(fn ($o) => ['playerId' => $o['playerId'], 'sale' => $o['salePrice'], 'value' => (float) ($o['salePrice'] - $o['projected'][$horizon])], $sellable),
            $deployableCash,
            (int) config('fantasy.trading.capital_step.make_money'),
        );

        $soldIds = array_flip($result['sells']);
        $chosen = [];
        foreach ($result['buys'] as $b) {
            $chosen[$b['playerId']] = $b['key'];
        }

        $movements = [];
        foreach ($sellable as $own) {
            if (isset($soldIds[$own['playerId']])) {
                $movements[] = $this->sellMovement($own, $horizon);
            }
        }
        $buyRows = [];
        foreach ($profitable as $c) {
            if (($chosen[$c['playerId']] ?? null) === $c['source']) {
                $buyRows[] = $c;
            }
        }
        // Things to do today first, then clauses that only unlock later; best profit first within each.
        usort($buyRows, fn ($a, $b) => ($a['payableInDays'] ?? 0) <=> ($b['payableInDays'] ?? 0)
            ?: $b['profit'][$horizon] <=> $a['profit'][$horizon]
            ?: $a['playerId'] <=> $b['playerId']);
        foreach ($buyRows as $c) {
            $view = $this->candidates->view($c, $horizon);
            $explanation = ($c['payableInDays'] ?? null) !== null
                ? $this->laterClauseExplanation($c, $horizon)
                : TradingExplainer::forRow($c, $horizon, $c['source']);
            $movements[] = $view + [
                'type' => $c['source'] === 'CLAUSE' ? 'CLAUSE' : 'BUY',
                'payableInDays' => $c['payableInDays'] ?? null,
                'explanation' => $explanation,
            ];
        }
        foreach ($movements as $i => &$m) {
            $m['step'] = $i + 1;
        }
        unset($m);

        $summary = $this->summarize($buyRows, $sellable, $soldIds, $cash, $reserve, $horizon, $result);
        $holds = $this->holds($pool['own'], $soldIds, $horizon);
        $hasBuys = $buyRows !== [];

        return [
            'state' => $hasBuys ? 'OK' : 'KEEP_CASH',
            'horizon' => $horizon,
            'capital' => [
                'currentCash' => $cash,
                'minimumCashReserve' => $reserve,
                'reserveRespected' => $respectReserve,
                'salesAllowed' => $allowSales,
                'deployableCash' => $deployableCash,
                'possibleCapitalFromSales' => $possibleFromSales,
                'totalDeployableCapital' => $deployableCash + $possibleFromSales,
                'salesAreEstimates' => (bool) array_filter($sellable, fn ($o) => $o['saleKind'] === 'ESTIMATE'),
            ],
            'minimumProfitPerMove' => $minProfit,
            'movements' => $movements,
            'holds' => $holds,
            'summary' => $summary,
            'keepCash' => $hasBuys ? null : $this->keepCashReason($pool, $eligible, $profitable, $deployableCash + $possibleFromSales),
            'alternatives' => $this->alternatives($profitable, $chosen, $deployableCash + $possibleFromSales, $horizon),
            'excluded' => $this->candidates->excludedSummary($excluded, $horizon),
            'market' => ['listings' => count($pool['market']), 'clauses' => count($pool['clause'])],
            'matching' => $pool['matching'],
            'explanations' => $this->planExplanations($summary, $hasBuys, $horizon),
        ];
    }

    /**
     * A clause that is locked today but unlocks before the horizon can still
     * be paid within it, so for this horizon it is a regular (later) option.
     * Shielded clauses and ones unlocking at or after the horizon stay excluded.
     *
     * Only the days you would own him count: you pay on the unlock day the
     * clause or, if his value has overtaken it by then, that value (a clause
     * never stays below the player's value), and the gain is his projected
     * value at the horizon minus that price. Growth while he is still the
     * rival's is never credited to you.
     *
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function withUnlockWindow(array $candidate, int $horizon): array
    {
        $days = $candidate['daysUntilUnlock'] ?? null;

        if (($candidate['excludeReason'] ?? null) !== 'CLAUSE_LOCKED' || $days === null || $days >= $horizon || $candidate['profit'] === null) {
            return $candidate;
        }

        if (($candidate['confidence']['score'] ?? 0) < (int) config('fantasy.trading.min_confidence_to_recommend')) {
            return array_merge($candidate, ['excludeReason' => 'LOW_CONFIDENCE']);
        }

        $valueAtUnlock = $this->projector->project(
            (float) $candidate['economicValue'],
            (float) ($candidate['growth']['expectedDailyGrowth'] ?? 0.0),
            $days,
        );
        $cost = (int) round(max((float) $candidate['clauseValue'], $valueAtUnlock));
        $profit = (int) round($candidate['projected'][$horizon] - $cost);

        $candidate['profit'][$horizon] = $profit;
        $candidate['roi'][$horizon] = $cost > 0 ? $profit / $cost : null;

        return array_merge($candidate, [
            'eligible' => true,
            'excludeReason' => null,
            'payableInDays' => $days,
            'ownedDays' => $horizon - $days,
            'valueAtUnlock' => (int) round($valueAtUnlock),
            'acquisitionCost' => $cost,
        ]);
    }

    /** @param array<string, mixed> $c */
    private function laterClauseExplanation(array $c, int $horizon): string
    {
        $days = $c['payableInDays'];
        $owned = $c['ownedDays'];

        return sprintf(
            'Encara blindada: es podrà pagar d\'aquí %d %s, si el rival no puja la clàusula abans. Pagaries %s (%s) i només el tindries %d %s: benefici esperat a %dD %s (rendiment %s). No es compta el que s\'hagi revaloritzat abans que sigui teu.',
            $days,
            $days === 1 ? 'dia' : 'dies',
            TradingExplainer::money($c['acquisitionCost']),
            $c['acquisitionCost'] > $c['clauseValue']
                ? 'el valor previst aquell dia, que ja haurà superat la clàusula de '.TradingExplainer::money($c['clauseValue'])
                : 'la clàusula',
            $owned,
            $owned === 1 ? 'dia' : 'dies',
            $horizon,
            TradingExplainer::signedMoney($c['profit'][$horizon]),
            TradingExplainer::percent($c['roi'][$horizon]),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $profitable
     * @return list<array{playerId: int, options: list<array{key: string, cost: int, profit: float}>}>
     */
    private function buyGroups(array $profitable, int $horizon): array
    {
        $groups = [];
        foreach ($profitable as $c) {
            $groups[$c['playerId']] ??= ['playerId' => $c['playerId'], 'options' => []];
            $groups[$c['playerId']]['options'][] = ['key' => $c['source'], 'cost' => (int) $c['acquisitionCost'], 'profit' => (float) $c['profit'][$horizon]];
        }

        return array_values($groups);
    }

    /**
     * @param  array<string, mixed>  $own
     * @return array<string, mixed>
     */
    private function sellMovement(array $own, int $horizon): array
    {
        $view = $this->candidates->view($own, $horizon);
        $evolution = $own['evolution'][$horizon];
        $estimate = $own['saleKind'] === 'ESTIMATE';

        $sentence = sprintf(
            'Vendre per %s%s per alliberar capital. ',
            TradingExplainer::money($own['salePrice']),
            $estimate ? ' (estimació de mercat, no és una oferta real)' : ' (oferta pendent)',
        );
        $sentence .= $evolution >= 0
            ? sprintf('Renuncies a una evolució esperada de %s a %dD.', TradingExplainer::signedMoney($evolution), $horizon)
            : sprintf('A més, evites una pèrdua esperada de %s a %dD.', TradingExplainer::money(abs($evolution)), $horizon);

        return $view + [
            'type' => 'SELL',
            'proceeds' => $own['salePrice'],
            'sellImpact' => (int) ($own['salePrice'] - $own['projected'][$horizon]),
            'explanation' => $sentence,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $own
     * @param  array<int, int>  $soldIds
     * @return list<array<string, mixed>>
     */
    private function holds(array $own, array $soldIds, int $horizon): array
    {
        $holds = [];
        foreach ($own as $o) {
            if (isset($soldIds[$o['playerId']])) {
                continue;
            }
            $evolution = $o['evolution'][$horizon] ?? null;
            $holds[] = $this->candidates->view($o, $horizon) + [
                'type' => 'HOLD',
                'explanation' => $evolution === null
                    ? 'Es manté: sense dades de FútbolFantasy no es pot comparar amb vendre.'
                    : sprintf('Es manté: evolució esperada de %s a %dD i cap compra millor que justifiqui vendre\'l.', TradingExplainer::signedMoney($evolution), $horizon),
            ];
        }

        usort($holds, fn ($a, $b) => ($b['gain'] ?? -INF) <=> ($a['gain'] ?? -INF) ?: $a['playerId'] <=> $b['playerId']);

        return $holds;
    }

    /**
     * @param  list<array<string, mixed>>  $buyRows
     * @param  list<array<string, mixed>>  $sellable
     * @param  array<int, int>  $soldIds
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function summarize(array $buyRows, array $sellable, array $soldIds, int $cash, int $reserve, int $horizon, array $result): array
    {
        $invested = (int) array_sum(array_column($buyRows, 'acquisitionCost'));
        $marketCost = (int) array_sum(array_map(fn ($r) => $r['source'] === 'MARKET' ? $r['acquisitionCost'] : 0, $buyRows));
        $clauseCost = $invested - $marketCost;
        $proceeds = (int) array_sum(array_map(fn ($o) => isset($soldIds[$o['playerId']]) ? $o['salePrice'] : 0, $sellable));
        $available = $cash + $proceeds;
        $cashAfter = $available - $invested;
        $scores = array_map(fn ($r) => $r['confidence']['score'], $buyRows);
        $purchaseProfit = (int) round($result['purchaseProfit']);
        $sellImpact = (int) round($result['saleValue']);

        return [
            'capitalToInvest' => $invested,
            'capitalFromSales' => $proceeds,
            'cashAfter' => $cashAfter,
            'expectedProfit' => $buyRows ? $purchaseProfit : null,
            'roi' => $invested > 0 ? $purchaseProfit / $invested : null,
            'sellImpact' => $soldIds ? $sellImpact : null,
            'netExpectedGain' => $buyRows || $soldIds ? $purchaseProfit + $sellImpact : null,
            'confidence' => $scores ? (int) round(array_sum($scores) / count($scores)) : null,
            'exactBalance' => $buyRows !== [] && $cashAfter === $reserve,
            'counts' => [
                'buy' => count(array_filter($buyRows, fn ($r) => $r['source'] === 'MARKET')),
                'clause' => count(array_filter($buyRows, fn ($r) => $r['source'] === 'CLAUSE')),
                'sell' => count($soldIds),
            ],
            'distribution' => [
                'marketPct' => $available > 0 ? $marketCost / $available : null,
                'clausePct' => $available > 0 ? $clauseCost / $available : null,
                'cashPct' => $available > 0 ? max(0, $cashAfter) / $available : null,
            ],
            'horizon' => $horizon,
        ];
    }

    /**
     * Why the answer is "buy nothing", most specific reason first.
     *
     * @param  array<string, mixed>  $pool
     * @param  list<array<string, mixed>>  $eligible
     * @param  list<array<string, mixed>>  $profitable
     * @return array{reason: string, message: string}
     */
    private function keepCashReason(array $pool, array $eligible, array $profitable, int $totalCapital): array
    {
        if ($pool['market'] === [] && $pool['clause'] === []) {
            return ['reason' => 'NO_OPPORTUNITIES', 'message' => 'No hi ha cap jugador al mercat ni clàusules de rivals a valorar.'];
        }
        if ($eligible === []) {
            return ['reason' => 'NO_ELIGIBLE_CANDIDATE', 'message' => 'Cap candidat és elegible: tots tenen dades insuficients, una MaxBid massa baixa o una clàusula no pagable ara.'];
        }
        if ($profitable === []) {
            return ['reason' => 'NO_PROFITABLE_CANDIDATE', 'message' => 'Cap candidat elegible té un benefici esperat que superi el mínim configurat.'];
        }

        $cheapest = min(array_column($profitable, 'acquisitionCost'));
        if ($cheapest > $totalCapital) {
            return ['reason' => 'INSUFFICIENT_CASH', 'message' => sprintf('Hi ha oportunitats rendibles, però la més barata costa %s i el capital total desplegable és %s.', TradingExplainer::money($cheapest), TradingExplainer::money($totalCapital))];
        }

        return ['reason' => 'NO_PROFITABLE_CANDIDATE', 'message' => 'Cap combinació de compres millora mantenir la caixa.'];
    }

    /**
     * The best profitable candidates that did not make the plan, and why.
     *
     * @param  list<array<string, mixed>>  $profitable
     * @param  array<int, string>  $chosen  playerId => chosen route
     * @return list<array<string, mixed>>
     */
    private function alternatives(array $profitable, array $chosen, int $totalCapital, int $horizon): array
    {
        $rest = array_filter($profitable, fn ($c) => ($chosen[$c['playerId']] ?? null) !== $c['source']);
        usort($rest, fn ($a, $b) => $b['profit'][$horizon] <=> $a['profit'][$horizon] ?: $a['playerId'] <=> $b['playerId']);

        return array_map(function ($c) use ($chosen, $totalCapital, $horizon) {
            $reason = isset($chosen[$c['playerId']]) ? 'OTHER_ROUTE_CHOSEN'
                : ($c['acquisitionCost'] > $totalCapital ? 'INSUFFICIENT_CAPITAL' : 'CAPITAL_BETTER_USED_ELSEWHERE');

            return $this->candidates->view($c, $horizon) + ['notChosenReason' => $reason];
        }, array_slice($rest, 0, 8));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function planExplanations(array $summary, bool $hasBuys, int $horizon): array
    {
        if (! $hasBuys) {
            return ['Ara mateix no compraria res: mantenir la caixa és millor que qualsevol compra disponible a '.$horizon.'D.'];
        }

        $lines = [sprintf(
            'Invertir %s genera un benefici esperat de %s a %dD (ROI %s).',
            TradingExplainer::money($summary['capitalToInvest']),
            TradingExplainer::signedMoney($summary['expectedProfit']),
            $horizon,
            TradingExplainer::percent($summary['roi']),
        )];

        if ($summary['counts']['sell'] > 0) {
            $lines[] = sprintf('Vendre %d jugador(s) aporta %s de capital (estimat) per finançar-ho.', $summary['counts']['sell'], TradingExplainer::money($summary['capitalFromSales']));
        }
        if ($summary['exactBalance']) {
            $lines[] = 'El pla fa servir tot el capital disponible fins a la reserva.';
        }

        return $lines;
    }
}
