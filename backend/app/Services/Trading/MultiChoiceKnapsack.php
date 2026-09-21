<?php

namespace App\Services\Trading;

/**
 * Deterministic multiple-choice 0/1 knapsack over an integer capacity grid:
 * from each group (one per player) pick at most one option, maximizing total
 * value. A player who is both on the market and has a clause is one group
 * with two options, so he can never be bought twice and the two routes
 * compete for the same capital. Pure — no I/O, no randomness; ties always
 * keep the earlier choice (skipping a group beats taking an equal-value
 * option), so the same input always yields the same plan.
 */
final class MultiChoiceKnapsack
{
    private const NEG = -INF;

    /**
     * @param  list<list<array{w: int, v: float}>>  $groups  options per group; w = weight on the grid, v = value
     * @param  bool  $exact  true: table[c] is the best value with weight *exactly* c (else -INF); false: at most c
     * @return array{table: list<float>, choices: list<list<int>>} choices[g][c] = option index taken at group g for capacity c, -1 = skipped
     */
    public static function solve(array $groups, int $capacity, bool $exact = false): array
    {
        $row = array_fill(0, $capacity + 1, $exact ? self::NEG : 0.0);
        if ($exact) {
            $row[0] = 0.0;
        }

        $choices = [];

        foreach ($groups as $g => $options) {
            $next = $row;
            $choice = array_fill(0, $capacity + 1, -1);

            foreach ($options as $o => $option) {
                $w = $option['w'];
                for ($c = $w; $c <= $capacity; $c++) {
                    $prev = $row[$c - $w];
                    if ($prev === self::NEG) {
                        continue;
                    }
                    $candidate = $prev + $option['v'];
                    if ($candidate > $next[$c]) {
                        $next[$c] = $candidate;
                        $choice[$c] = $o;
                    }
                }
            }

            $row = $next;
            $choices[$g] = $choice;
        }

        return ['table' => $row, 'choices' => $choices];
    }

    /**
     * @param  list<list<array{w: int, v: float}>>  $groups
     * @param  list<list<int>>  $choices
     * @return array<int, int> group index => chosen option index
     */
    public static function reconstruct(array $groups, array $choices, int $capacity): array
    {
        $picks = [];
        $c = $capacity;

        for ($g = count($groups) - 1; $g >= 0; $g--) {
            $o = $choices[$g][$c];
            if ($o >= 0) {
                $picks[$g] = $o;
                $c -= $groups[$g][$o]['w'];
            }
        }

        return $picks;
    }
}
