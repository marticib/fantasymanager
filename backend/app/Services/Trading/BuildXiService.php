<?php

namespace App\Services\Trading;

use App\Models\FantasyAccount;

/**
 * "Construir onze": the cheapest / most profitable way to end up with a valid
 * starting XI, given your own players (free), the market and the rivals'
 * clauses. Assembles the candidate pool (TradingCandidateService), hands the
 * position-constrained optimization to BuildXiOptimizer, and reports it with
 * a hard split between the two very different things an XI contains:
 *
 * - the *new acquisitions* — what you pay (`cost`) and what those purchases are
 *   expected to earn (`newAcquisitionsProfit`, `newAcquisitionsRoi`);
 * - the *already-owned* players — cost 0 here, whose expected value evolution
 *   (`ownedEvolution`) accrues whether or not they start, so it is reported but
 *   never used to choose the XI.
 *
 * Formation validity is only a position constraint (config
 * fantasy.trading.formations). Nothing sporting is considered.
 */
class BuildXiService
{
    public function __construct(
        private readonly TradingCandidateService $candidates,
        private readonly BuildXiOptimizer $optimizer,
    ) {}

    /** @return array<string, mixed> */
    public function build(FantasyAccount $account, string $strategy, int $horizon): array
    {
        $pool = $this->candidates->pool($account);

        if ($pool === null) {
            return ['state' => 'NO_LEAGUE', 'strategy' => $strategy, 'horizon' => $horizon, 'lineup' => [], 'message' => 'Encara no hi ha cap lliga activa.'];
        }

        $cash = $pool['cash'];
        $reserve = (int) $pool['rules']['minimum_cash_reserve'];
        // Only "balanced" keeps the reserve back; the other two may spend all the cash.
        $budget = $strategy === 'balanced' ? max(0, $cash - $reserve) : $cash;

        $acquisitions = array_values(array_filter([...$pool['market'], ...$pool['clause']], fn ($c) => $c['eligible']));
        $excluded = array_values(array_filter([...$pool['market'], ...$pool['clause']], fn ($c) => ! $c['eligible']));

        $byId = [];
        foreach ($pool['own'] as $own) {
            $byId['OWN:'.$own['playerId']] = $own;
        }
        foreach ($acquisitions as $c) {
            $byId[$c['source'].':'.$c['playerId']] = $c;
        }

        $formations = config('fantasy.trading.formations');
        $result = $this->optimizer->optimize($this->optimizerPlayers($pool['own'], $acquisitions, $horizon), $formations, $budget, (int) config('fantasy.trading.capital_step.build_xi'), $strategy);

        $base = [
            'strategy' => $strategy,
            'horizon' => $horizon,
            'formations' => array_keys($formations),
            'capital' => ['cash' => $cash, 'minimumCashReserve' => $reserve, 'budget' => $budget],
            'excluded' => $this->candidates->excludedSummary($excluded, $horizon),
            'ownCanFillXi' => $this->ownCanFill($pool['own'], $formations),
            'matching' => $pool['matching'],
            'ownWithoutEvolution' => count(array_filter($pool['own'], fn ($o) => $o['evolution'] === null)),
        ];

        if ($result['status'] === 'IMPOSSIBLE') {
            return $base + [
                'state' => 'IMPOSSIBLE_XI',
                'lineup' => [],
                'impossible' => [
                    'reason' => $result['reason'],
                    'missing' => $result['missing'],
                    'minimumCostToComplete' => $result['minimumCost'],
                    'availableBudget' => $budget,
                    'shortfall' => $result['minimumCost'] !== null ? max(0, $result['minimumCost'] - $budget) : null,
                    'message' => $this->impossibleMessage($result, $budget, $strategy, $cash, $reserve),
                ],
            ];
        }

        $lineup = [];
        foreach ($result['picks'] as $pick) {
            $row = $byId[$pick['key'].':'.$pick['playerId']];
            $view = $this->candidates->view($row, $horizon);
            $view['origin'] = $row['source'];
            $view['explanation'] = TradingExplainer::forRow($row, $horizon, $row['source']);
            $lineup[] = $view;
        }

        $summary = $this->summarize($lineup, $cash, $reserve, $horizon);
        $hasPurchases = $summary['counts']['market'] + $summary['counts']['clause'] > 0;

        return $base + [
            'state' => $hasPurchases ? 'OK' : 'ALREADY_HAVE_XI',
            'formation' => $result['formation'],
            'lineup' => $lineup,
            'summary' => $summary,
            'explanations' => $this->planExplanations($strategy, $summary, $result['formation'], $horizon, $hasPurchases),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $own
     * @param  list<array<string, mixed>>  $acquisitions
     * @return list<array{playerId: int, position: string, options: list<array<string, mixed>>}>
     */
    private function optimizerPlayers(array $own, array $acquisitions, int $horizon): array
    {
        $players = [];

        foreach ($own as $o) {
            if ($o['position']) {
                $players['OWN:'.$o['playerId']] = [
                    'playerId' => $o['playerId'],
                    'position' => $o['position'],
                    'options' => [['key' => 'OWN', 'cost' => 0, 'profit' => 0.0, 'starter' => $o['isStarter']]],
                ];
            }
        }

        // A player on the market *and* holding a clause is one entry with both
        // routes as options — never bought twice, and the routes compete.
        foreach ($acquisitions as $c) {
            if (! $c['position']) {
                continue;
            }
            $players['ACQ:'.$c['playerId']] ??= ['playerId' => $c['playerId'], 'position' => $c['position'], 'options' => []];
            $players['ACQ:'.$c['playerId']]['options'][] = ['key' => $c['source'], 'cost' => (int) $c['acquisitionCost'], 'profit' => (float) $c['profit'][$horizon]];
        }

        return array_values($players);
    }

    /**
     * @param  list<array<string, mixed>>  $lineup
     * @return array<string, mixed>
     */
    private function summarize(array $lineup, int $cash, int $reserve, int $horizon): array
    {
        $buys = array_filter($lineup, fn ($r) => $r['origin'] !== 'OWN');
        $owned = array_filter($lineup, fn ($r) => $r['origin'] === 'OWN');

        $cost = (int) array_sum(array_column($buys, 'cost'));
        $marketCost = (int) array_sum(array_column(array_filter($buys, fn ($r) => $r['origin'] === 'MARKET'), 'cost'));
        $clauseCost = $cost - $marketCost;
        $profit = (int) array_sum(array_column($buys, 'gain'));
        $knownEvolutions = array_filter(array_column($owned, 'gain'), fn ($g) => $g !== null);
        $cashAfter = $cash - $cost;
        $scores = array_map(fn ($r) => $r['confidence']['score'], $lineup);

        return [
            'acquisitionCost' => $cost,
            'cashBefore' => $cash,
            'cashAfter' => $cashAfter,
            'minimumCashReserve' => $reserve,
            'respectsReserve' => $cashAfter >= $reserve,
            'newAcquisitionsProfit' => $buys ? $profit : null,
            'newAcquisitionsRoi' => $cost > 0 ? $profit / $cost : null,
            // Owned players' evolution accrues either way — informational, never part of the choice.
            'ownedEvolution' => $knownEvolutions ? (int) array_sum($knownEvolutions) : null,
            'ownedWithoutEvolution' => count($owned) - count($knownEvolutions),
            'counts' => [
                'own' => count($owned),
                'market' => count(array_filter($buys, fn ($r) => $r['origin'] === 'MARKET')),
                'clause' => count(array_filter($buys, fn ($r) => $r['origin'] === 'CLAUSE')),
            ],
            'distribution' => [
                'marketPct' => $cash > 0 ? $marketCost / $cash : null,
                'clausePct' => $cash > 0 ? $clauseCost / $cash : null,
                'cashPct' => $cash > 0 ? max(0, $cashAfter) / $cash : null,
            ],
            'confidence' => $scores ? (int) round(array_sum($scores) / count($scores)) : null,
            'horizon' => $horizon,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function planExplanations(string $strategy, array $summary, string $formation, int $horizon, bool $hasPurchases): array
    {
        $lines = [];
        $label = ['max_return' => 'Màxima rendibilitat', 'balanced' => 'Equilibrat', 'min_cost' => 'Mínim cost'][$strategy];

        $lines[] = "Estratègia {$label}, formació {$formation}.";

        if (! $hasPurchases) {
            $lines[] = 'Ja pots formar un onze vàlid només amb jugadors que ja tens: cost 0 €.';

            return $lines;
        }

        $lines[] = sprintf(
            'Cal invertir %s per completar l\'onze; et quedarien %s de caixa.',
            TradingExplainer::money($summary['acquisitionCost']),
            TradingExplainer::money($summary['cashAfter']),
        );
        $lines[] = sprintf(
            'Les noves adquisicions haurien de generar %s a %dD (ROI %s); l\'evolució dels jugadors que ja tens (%s) es mostra a part.',
            TradingExplainer::signedMoney($summary['newAcquisitionsProfit']),
            $horizon,
            TradingExplainer::percent($summary['newAcquisitionsRoi']),
            TradingExplainer::signedMoney($summary['ownedEvolution']),
        );

        if ($strategy === 'balanced') {
            $lines[] = sprintf('Es manté la reserva mínima de caixa (%s).', TradingExplainer::money($summary['minimumCashReserve']));
        } elseif (! $summary['respectsReserve']) {
            $lines[] = sprintf('Atenció: la caixa final queda per sota de la reserva mínima (%s).', TradingExplainer::money($summary['minimumCashReserve']));
        }

        return $lines;
    }

    /** @param array<string, mixed> $result */
    private function impossibleMessage(array $result, int $budget, string $strategy, int $cash, int $reserve): string
    {
        if ($result['reason'] === 'MISSING_POSITION') {
            $names = ['GK' => 'porters', 'DF' => 'defenses', 'MF' => 'migcampistes', 'FW' => 'davanters'];
            $parts = [];
            foreach ($result['missing'] as $pos => $gap) {
                $parts[] = "{$gap} ".$names[$pos];
            }

            return 'No hi ha prou jugadors elegibles per cobrir totes les posicions de cap formació. Et falten: '.implode(', ', $parts).'.';
        }

        $need = TradingExplainer::money($result['minimumCost']);

        if ($strategy === 'balanced' && $cash >= $result['minimumCost'] && $budget < $result['minimumCost']) {
            return "Completar l'onze costaria com a mínim {$need}, però només hi ha ".TradingExplainer::money($budget).' disponibles mantenint la reserva mínima de '.TradingExplainer::money($reserve).'.';
        }

        return "Completar l'onze costaria com a mínim {$need} i només hi ha ".TradingExplainer::money($budget).' disponibles.';
    }

    /**
     * @param  list<array<string, mixed>>  $own
     * @param  array<string, array<string, int>>  $formations
     */
    private function ownCanFill(array $own, array $formations): bool
    {
        $counts = array_count_values(array_filter(array_column($own, 'position')));

        foreach ($formations as $need) {
            $ok = true;
            foreach ($need as $pos => $n) {
                $ok = $ok && ($counts[$pos] ?? 0) >= $n;
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
