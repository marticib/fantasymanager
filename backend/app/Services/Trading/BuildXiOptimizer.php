<?php

namespace App\Services\Trading;

/**
 * "Construir onze": picks a valid starting XI (a configured formation filled
 * position by position) out of your own players — who cost nothing — and the
 * purchasable options, under a spending budget. It is a pure position
 * constraint: no sporting quality enters anywhere, only money.
 *
 * Strategies (the value each option contributes; the DP maximizes the sum):
 * - max_return: purchase profit, cost only as a vanishing tie-break — spends
 *   the budget wherever it earns most;
 * - balanced:   same objective, but the *caller* hands in a budget that already
 *   keeps the minimum cash reserve back;
 * - min_cost:   cheapest valid XI first (cost dominates), profit only breaks ties.
 *
 * Exact up to the money grid: one DP per position (exactly k of its players,
 * per cost bucket), then the four positions are convolved per formation and
 * the best formation wins. Costs round up on the grid, so a plan is never over
 * budget. A player who is both a market listing and a clause is a single group
 * (one option per route), so he is never picked twice. Deterministic: ties keep
 * the earlier formation / lower cost / earlier option.
 */
final class BuildXiOptimizer
{
    public const STRATEGIES = ['max_return', 'balanced', 'min_cost'];

    private const POSITIONS = ['GK', 'DF', 'MF', 'FW'];

    private const MAX_GRID = 400;

    /** Own starters win otherwise-equal ties, so the plan doesn't shuffle a settled XI for nothing. */
    private const STARTER_TIE_BREAK = 0.001;

    /**
     * @param  list<array{playerId: int, position: string, options: list<array{key: string, cost: int, profit: float, starter?: bool}>}>  $players
     * @param  array<string, array<string, int>>  $formations  name => [GK=>1, DF=>4, MF=>4, FW=>2]
     * @return array{
     *     status: 'OK'|'IMPOSSIBLE',
     *     reason: ?string,
     *     formation: ?string,
     *     picks: list<array{playerId: int, position: string, key: string}>,
     *     cost: int,
     *     profit: float,
     *     minimumCost: ?int,
     *     missing: array<string, int>
     * }
     */
    public function optimize(array $players, array $formations, int $budget, int $step, string $strategy): array
    {
        $byPosition = array_fill_keys(self::POSITIONS, []);
        foreach ($players as $player) {
            if (isset($byPosition[$player['position']])) {
                $byPosition[$player['position']][] = $player;
            }
        }
        foreach ($byPosition as &$group) {
            usort($group, fn ($a, $b) => $a['playerId'] <=> $b['playerId']);
        }
        unset($group);

        // 1) Which formations can be filled at all, and what is the cheapest way to fill each?
        $feasible = [];
        $bestShortfall = null;
        $minimumCost = null;

        foreach ($formations as $name => $need) {
            $shortfall = [];
            foreach (self::POSITIONS as $pos) {
                $gap = ($need[$pos] ?? 0) - count($byPosition[$pos]);
                if ($gap > 0) {
                    $shortfall[$pos] = $gap;
                }
            }

            if ($shortfall !== []) {
                if ($bestShortfall === null || array_sum($shortfall) < array_sum($bestShortfall)) {
                    $bestShortfall = $shortfall;
                }

                continue;
            }

            $feasible[$name] = $need;
            $cost = 0;
            foreach (self::POSITIONS as $pos) {
                $cheapest = array_map(fn ($p) => min(array_column($p['options'], 'cost')), $byPosition[$pos]);
                sort($cheapest);
                $cost += array_sum(array_slice($cheapest, 0, $need[$pos] ?? 0));
            }
            $minimumCost = $minimumCost === null ? $cost : min($minimumCost, $cost);
        }

        if ($feasible === []) {
            return $this->impossible('MISSING_POSITION', null, $bestShortfall ?? []);
        }
        if ($minimumCost > $budget) {
            return $this->impossible('INSUFFICIENT_CASH', $minimumCost, []);
        }

        // 2) Money grid, coarsened just enough to keep the convolutions cheap.
        $step = max($step, (int) (ceil($budget / self::MAX_GRID / 10_000) * 10_000));
        $capacity = intdiv($budget, $step);

        [$a, $b] = match ($strategy) {
            'min_cost' => [1e-9, 1.0],
            default => [1.0, 1e-6],
        };

        // 3) One table per position: best value for exactly j players at each cost bucket.
        $maxNeed = array_fill_keys(self::POSITIONS, 0);
        foreach ($feasible as $need) {
            foreach (self::POSITIONS as $pos) {
                $maxNeed[$pos] = max($maxNeed[$pos], $need[$pos] ?? 0);
            }
        }

        $tables = [];
        foreach (self::POSITIONS as $pos) {
            $tables[$pos] = $this->positionTable($byPosition[$pos], $maxNeed[$pos], $capacity, $step, $a, $b);
        }

        // 4) Combine positions per formation, keep the best.
        $convCache = [];
        $best = null;

        foreach ($feasible as $name => $need) {
            $level = $tables['GK']['dp'][$need['GK']];
            $chain = [];
            $key = 'GK'.$need['GK'];

            foreach (['DF', 'MF', 'FW'] as $pos) {
                $key .= '|'.$pos.$need[$pos];
                if (! isset($convCache[$key])) {
                    $convCache[$key] = $this->convolve($level, $tables[$pos]['dp'][$need[$pos]], $capacity);
                }
                $chain[$pos] = $convCache[$key];
                $level = $convCache[$key]['table'];
            }

            for ($c = 0; $c <= $capacity; $c++) {
                if ($level[$c] === -INF) {
                    continue;
                }
                if ($best === null || $level[$c] > $best['value']) {
                    $best = ['value' => $level[$c], 'cost' => $c, 'formation' => $name, 'need' => $need, 'chain' => $chain];
                }
            }
        }

        if ($best === null) {
            return $this->impossible('INSUFFICIENT_CASH', $minimumCost, []);
        }

        return $this->assemble($best, $byPosition, $tables);
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @return array{dp: list<list<float>>, choices: list<list<list<int>>>, grid: list<list<array{w: int, v: float}>>}
     */
    private function positionTable(array $group, int $maxK, int $capacity, int $step, float $a, float $b): array
    {
        $dp = array_fill(0, $maxK + 1, array_fill(0, $capacity + 1, -INF));
        $dp[0][0] = 0.0;
        $choices = [];
        $grid = [];

        foreach ($group as $g => $player) {
            $options = array_map(fn ($o) => [
                'w' => (int) ceil($o['cost'] / $step),
                'v' => $a * $o['profit'] - $b * $o['cost'] + (($o['starter'] ?? false) ? self::STARTER_TIE_BREAK : 0.0),
            ], $player['options']);
            $grid[$g] = $options;

            $next = $dp;
            $choice = array_fill(0, $maxK + 1, array_fill(0, $capacity + 1, -1));

            for ($j = 1; $j <= $maxK; $j++) {
                foreach ($options as $o => $option) {
                    for ($c = $option['w']; $c <= $capacity; $c++) {
                        $prev = $dp[$j - 1][$c - $option['w']];
                        if ($prev === -INF) {
                            continue;
                        }
                        $candidate = $prev + $option['v'];
                        if ($candidate > $next[$j][$c]) {
                            $next[$j][$c] = $candidate;
                            $choice[$j][$c] = $o;
                        }
                    }
                }
            }

            $dp = $next;
            $choices[$g] = $choice;
        }

        return ['dp' => $dp, 'choices' => $choices, 'grid' => $grid];
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     * @return array{table: list<float>, split: list<int>}
     */
    private function convolve(array $left, array $right, int $capacity): array
    {
        $table = array_fill(0, $capacity + 1, -INF);
        $split = array_fill(0, $capacity + 1, 0);

        for ($l = 0; $l <= $capacity; $l++) {
            if ($left[$l] === -INF) {
                continue;
            }
            for ($r = 0; $l + $r <= $capacity; $r++) {
                if ($right[$r] === -INF) {
                    continue;
                }
                $value = $left[$l] + $right[$r];
                if ($value > $table[$l + $r]) {
                    $table[$l + $r] = $value;
                    $split[$l + $r] = $l;
                }
            }
        }

        return ['table' => $table, 'split' => $split];
    }

    /**
     * @param  array<string, mixed>  $best
     * @param  array<string, list<array<string, mixed>>>  $byPosition
     * @param  array<string, array<string, mixed>>  $tables
     * @return array<string, mixed>
     */
    private function assemble(array $best, array $byPosition, array $tables): array
    {
        // Walk the convolution chain back to a cost bucket per position.
        $costs = [];
        $c = $best['cost'];
        foreach (['FW', 'MF', 'DF'] as $pos) {
            $left = $best['chain'][$pos]['split'][$c];
            $costs[$pos] = $c - $left;
            $c = $left;
        }
        $costs['GK'] = $c;

        $picks = [];
        $totalCost = 0;
        $totalProfit = 0.0;

        foreach (self::POSITIONS as $pos) {
            $j = $best['need'][$pos];
            $cc = $costs[$pos];

            for ($g = count($byPosition[$pos]) - 1; $g >= 0 && $j > 0; $g--) {
                $o = $tables[$pos]['choices'][$g][$j][$cc];
                if ($o < 0) {
                    continue;
                }
                $player = $byPosition[$pos][$g];
                $option = $player['options'][$o];
                $picks[] = ['playerId' => $player['playerId'], 'position' => $pos, 'key' => $option['key']];
                $totalCost += $option['cost'];
                $totalProfit += $option['profit'];
                $cc -= $tables[$pos]['grid'][$g][$o]['w'];
                $j--;
            }
        }

        usort($picks, fn ($x, $y) => [array_search($x['position'], self::POSITIONS), $x['playerId']] <=> [array_search($y['position'], self::POSITIONS), $y['playerId']]);

        return [
            'status' => 'OK',
            'reason' => null,
            'formation' => $best['formation'],
            'picks' => $picks,
            'cost' => $totalCost,
            'profit' => $totalProfit,
            'minimumCost' => null,
            'missing' => [],
        ];
    }

    /**
     * @param  array<string, int>  $missing
     * @return array<string, mixed>
     */
    private function impossible(string $reason, ?int $minimumCost, array $missing): array
    {
        return ['status' => 'IMPOSSIBLE', 'reason' => $reason, 'formation' => null, 'picks' => [], 'cost' => 0, 'profit' => 0.0, 'minimumCost' => $minimumCost, 'missing' => $missing];
    }
}
