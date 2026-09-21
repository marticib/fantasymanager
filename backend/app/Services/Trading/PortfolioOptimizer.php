<?php

namespace App\Services\Trading;

/**
 * "Guanyar diners": given a pool of purchasable options and a set of your own
 * players you could sell, picks the plan with the highest total expected gain
 * inside the capital you actually have — cash you hold now plus the proceeds
 * of whichever sales the plan itself proposes. It knows nothing about lineups,
 * positions or squad size, and "buy nothing" is a first-class outcome (an empty
 * plan), never a failure.
 *
 * Total gain = Σ profit of the purchases + Σ value of the sales, where a
 * sale's value is what selling changes vs holding (the appreciation you give
 * up if he was rising, the loss you avoid if he was falling). Sales are only
 * ever chosen because they pay for a better purchase or dodge a loss — never
 * on their own account.
 *
 * Solved exactly (up to the money grid) with two knapsacks instead of
 * enumerating sale subsets: g[k] = best sale value raising exactly k grid-units
 * of capital, f[b] = best purchase profit within b units, answer =
 * max over k of g[k] + f[base + k]. Costs round up and proceeds round down on
 * the grid, so the plan is never over budget. Deterministic; the earliest
 * (fewest-sales) plan wins ties.
 */
final class PortfolioOptimizer
{
    /**
     * @param  list<array{playerId: int, options: list<array{key: string, cost: int, profit: float}>}>  $buyGroups  one group per player; options are alternative routes (market / clause)
     * @param  list<array{playerId: int, sale: int, value: float}>  $sells
     * @return array{buys: list<array{playerId: int, key: string}>, sells: list<int>, purchaseProfit: float, saleValue: float, totalGain: float, gridCapital: int}
     */
    public function optimize(array $buyGroups, array $sells, int $baseCapital, int $step): array
    {
        $step = max(1, $step);
        $baseUnits = intdiv(max(0, $baseCapital), $step);

        usort($buyGroups, fn ($a, $b) => $a['playerId'] <=> $b['playerId']);
        usort($sells, fn ($a, $b) => $a['playerId'] <=> $b['playerId']);

        $sellUnits = array_map(fn ($s) => intdiv(max(0, $s['sale']), $step), $sells);
        $maxSellUnits = array_sum($sellUnits);
        $capacity = $baseUnits + $maxSellUnits;

        $buyGrid = array_map(
            fn ($group) => array_map(fn ($o) => ['w' => (int) ceil($o['cost'] / $step), 'v' => $o['profit']], $group['options']),
            $buyGroups,
        );
        $buySolution = MultiChoiceKnapsack::solve($buyGrid, $capacity, false);

        $sellGrid = array_map(fn ($s, $u) => [['w' => $u, 'v' => $s['value']]], $sells, $sellUnits);
        $sellSolution = MultiChoiceKnapsack::solve($sellGrid, $maxSellUnits, true);

        $bestK = 0;
        $bestTotal = $sellSolution['table'][0] + $buySolution['table'][$baseUnits];

        for ($k = 1; $k <= $maxSellUnits; $k++) {
            $sellValue = $sellSolution['table'][$k];
            if ($sellValue === -INF) {
                continue;
            }
            $total = $sellValue + $buySolution['table'][$baseUnits + $k];
            if ($total > $bestTotal) {
                $bestTotal = $total;
                $bestK = $k;
            }
        }

        $buys = [];
        $purchaseProfit = 0.0;
        foreach (MultiChoiceKnapsack::reconstruct($buyGrid, $buySolution['choices'], $baseUnits + $bestK) as $g => $o) {
            $option = $buyGroups[$g]['options'][$o];
            $buys[] = ['playerId' => $buyGroups[$g]['playerId'], 'key' => $option['key']];
            $purchaseProfit += $option['profit'];
        }

        $soldIds = [];
        $saleValue = 0.0;
        foreach (MultiChoiceKnapsack::reconstruct($sellGrid, $sellSolution['choices'], $bestK) as $g => $o) {
            $soldIds[] = $sells[$g]['playerId'];
            $saleValue += $sells[$g]['value'];
        }

        return [
            'buys' => $buys,
            'sells' => $soldIds,
            'purchaseProfit' => $purchaseProfit,
            'saleValue' => $saleValue,
            'totalGain' => $purchaseProfit + $saleValue,
            'gridCapital' => $capacity,
        ];
    }
}
