<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\BuildXiOptimizer;
use PHPUnit\Framework\TestCase;

class BuildXiOptimizerTest extends TestCase
{
    private const FORMATIONS = [
        '4-4-2' => ['GK' => 1, 'DF' => 4, 'MF' => 4, 'FW' => 2],
        '4-3-3' => ['GK' => 1, 'DF' => 4, 'MF' => 3, 'FW' => 3],
    ];

    private int $nextId = 1;

    private function own(string $position, bool $starter = false): array
    {
        return ['playerId' => $this->nextId++, 'position' => $position, 'options' => [['key' => 'OWN', 'cost' => 0, 'profit' => 0.0, 'starter' => $starter]]];
    }

    private function buy(string $position, int $cost, float $profit, string $key = 'MARKET'): array
    {
        return ['playerId' => $this->nextId++, 'position' => $position, 'options' => [['key' => $key, 'cost' => $cost, 'profit' => $profit]]];
    }

    /** @return list<array<string, mixed>> */
    private function squad(array $counts = ['GK' => 2, 'DF' => 5, 'MF' => 5, 'FW' => 3]): array
    {
        $players = [];
        foreach ($counts as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                $players[] = $this->own($position);
            }
        }

        return $players;
    }

    private function optimize(array $players, int $budget, string $strategy, array $formations = self::FORMATIONS): array
    {
        return (new BuildXiOptimizer)->optimize($players, $formations, $budget, 100_000, $strategy);
    }

    private function positions(array $result): array
    {
        return array_count_values(array_column($result['picks'], 'position'));
    }

    public function test_a_full_own_squad_forms_an_xi_at_zero_cost_for_every_strategy(): void
    {
        foreach (BuildXiOptimizer::STRATEGIES as $strategy) {
            $result = $this->optimize($this->squad(), 0, $strategy);

            $this->assertSame('OK', $result['status'], $strategy);
            $this->assertCount(11, $result['picks']);
            $this->assertSame(0, $result['cost']);
            $this->assertSame(['OWN'], array_unique(array_column($result['picks'], 'key')));
        }
    }

    public function test_the_xi_always_matches_a_formations_position_counts(): void
    {
        $result = $this->optimize($this->squad(), 0, 'min_cost');

        $this->assertContains($this->positions($result), [['GK' => 1, 'DF' => 4, 'MF' => 4, 'FW' => 2], ['GK' => 1, 'DF' => 4, 'MF' => 3, 'FW' => 3]]);
    }

    public function test_a_missing_position_is_reported_as_impossible_with_what_is_missing(): void
    {
        $result = $this->optimize($this->squad(['GK' => 0, 'DF' => 5, 'MF' => 5, 'FW' => 3]), 90_000_000, 'max_return');

        $this->assertSame('IMPOSSIBLE', $result['status']);
        $this->assertSame('MISSING_POSITION', $result['reason']);
        $this->assertSame(['GK' => 1], $result['missing']);
        $this->assertSame([], $result['picks']);
    }

    public function test_a_buyable_goalkeeper_fills_the_missing_position(): void
    {
        $players = [...$this->squad(['GK' => 0, 'DF' => 5, 'MF' => 5, 'FW' => 3]), $this->buy('GK', 3_000_000, 100_000.0)];

        $result = $this->optimize($players, 5_000_000, 'min_cost');

        $this->assertSame('OK', $result['status']);
        $this->assertSame(3_000_000, $result['cost']);
        $this->assertSame(1, $this->positions($result)['GK']);
    }

    public function test_insufficient_cash_is_impossible_and_reports_the_minimum_cost(): void
    {
        $players = [...$this->squad(['GK' => 0, 'DF' => 5, 'MF' => 5, 'FW' => 3]), $this->buy('GK', 4_000_000, 100_000.0), $this->buy('GK', 6_000_000, 900_000.0)];

        $result = $this->optimize($players, 3_999_999, 'max_return');

        $this->assertSame('IMPOSSIBLE', $result['status']);
        $this->assertSame('INSUFFICIENT_CASH', $result['reason']);
        $this->assertSame(4_000_000, $result['minimumCost']);
    }

    public function test_exactly_enough_cash_is_enough(): void
    {
        $players = [...$this->squad(['GK' => 0, 'DF' => 5, 'MF' => 5, 'FW' => 3]), $this->buy('GK', 4_000_000, 100_000.0)];

        $this->assertSame('OK', $this->optimize($players, 4_000_000, 'min_cost')['status']);
    }

    public function test_min_cost_picks_the_cheapest_valid_xi(): void
    {
        $players = [...$this->squad(['GK' => 1, 'DF' => 5, 'MF' => 5, 'FW' => 1]), $this->buy('FW', 8_000_000, 2_000_000.0), $this->buy('FW', 2_000_000, 100_000.0)];

        $result = $this->optimize($players, 50_000_000, 'min_cost');

        $this->assertSame(2_000_000, $result['cost']);
    }

    public function test_max_return_spends_where_profit_is_highest_and_replaces_free_own_players(): void
    {
        $players = [...$this->squad(), $this->buy('FW', 6_000_000, 1_500_000.0), $this->buy('FW', 3_000_000, 400_000.0)];

        $result = $this->optimize($players, 10_000_000, 'max_return');

        $this->assertEqualsWithDelta(1_900_000, $result['profit'], 0.5);
        $this->assertSame(9_000_000, $result['cost']);
    }

    public function test_max_return_never_buys_a_player_who_loses_money_when_an_own_player_can_play(): void
    {
        $players = [...$this->squad(), $this->buy('FW', 5_000_000, -300_000.0)];

        $result = $this->optimize($players, 50_000_000, 'max_return');

        $this->assertSame(0, $result['cost']);
    }

    public function test_a_forced_purchase_can_have_negative_profit_when_no_position_alternative_exists(): void
    {
        $players = [...$this->squad(['GK' => 0, 'DF' => 5, 'MF' => 5, 'FW' => 3]), $this->buy('GK', 3_000_000, -200_000.0)];

        $result = $this->optimize($players, 10_000_000, 'max_return');

        $this->assertSame('OK', $result['status']);
        $this->assertEqualsWithDelta(-200_000, $result['profit'], 0.5);
    }

    public function test_the_spend_never_exceeds_the_budget(): void
    {
        $players = [...$this->squad(), $this->buy('FW', 6_010_000, 1_500_000.0), $this->buy('MF', 4_020_000, 900_000.0), $this->buy('DF', 5_030_000, 800_000.0)];

        foreach ([0, 4_500_000, 9_000_000, 12_000_000, 20_000_000] as $budget) {
            $this->assertLessThanOrEqual($budget, $this->optimize($players, $budget, 'max_return')['cost']);
        }
    }

    public function test_a_player_on_the_market_and_with_a_clause_is_picked_once_and_the_routes_compete(): void
    {
        $twoRoutes = ['playerId' => 500, 'position' => 'FW', 'options' => [
            ['key' => 'MARKET', 'cost' => 3_000_000, 'profit' => 400_000.0],
            ['key' => 'CLAUSE', 'cost' => 5_000_000, 'profit' => 900_000.0],
        ]];

        $result = $this->optimize([...$this->squad(), $twoRoutes], 20_000_000, 'max_return');

        $ids = array_column($result['picks'], 'playerId');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertSame('CLAUSE', collect($result['picks'])->firstWhere('playerId', 500)['key']);

        $tight = $this->optimize([...$this->squad(), $twoRoutes], 3_500_000, 'max_return');
        $this->assertSame('MARKET', collect($tight['picks'])->firstWhere('playerId', 500)['key']);
    }

    public function test_it_picks_the_formation_that_suits_the_available_players(): void
    {
        // Only 4-3-3 is coverable: 3 forwards, 3 midfielders.
        $players = $this->squad(['GK' => 1, 'DF' => 4, 'MF' => 3, 'FW' => 3]);

        $this->assertSame('4-3-3', $this->optimize($players, 0, 'min_cost')['formation']);
    }

    public function test_the_same_input_always_gives_the_same_xi(): void
    {
        $players = [...$this->squad(), $this->buy('MF', 3_000_000, 500_000.0), $this->buy('MF', 3_000_000, 500_000.0)];

        $this->assertSame($this->optimize($players, 4_000_000, 'max_return'), $this->optimize($players, 4_000_000, 'max_return'));
    }

    public function test_an_empty_player_pool_is_a_missing_position_state(): void
    {
        $result = $this->optimize([], 10_000_000, 'balanced');

        $this->assertSame('MISSING_POSITION', $result['reason']);
    }

    public function test_it_matches_brute_force_on_a_small_random_pool(): void
    {
        mt_srand(7);
        $formations = ['1-1-1-1' => ['GK' => 1, 'DF' => 1, 'MF' => 1, 'FW' => 1]];

        for ($round = 0; $round < 15; $round++) {
            $this->nextId = 1;
            $players = [];
            foreach (['GK', 'DF', 'MF', 'FW'] as $position) {
                $players[] = $this->own($position);
                for ($i = 0; $i < 2; $i++) {
                    $players[] = $this->buy($position, mt_rand(5, 40) * 100_000, (float) (mt_rand(-2, 12) * 50_000));
                }
            }
            $budget = mt_rand(0, 80) * 100_000;

            $best = -INF;
            $byPos = [];
            foreach ($players as $p) {
                $byPos[$p['position']][] = $p['options'][0];
            }
            foreach ($byPos['GK'] as $g) {
                foreach ($byPos['DF'] as $d) {
                    foreach ($byPos['MF'] as $m) {
                        foreach ($byPos['FW'] as $f) {
                            if ($budget >= $g['cost'] + $d['cost'] + $m['cost'] + $f['cost']) {
                                $best = max($best, $g['profit'] + $d['profit'] + $m['profit'] + $f['profit']);
                            }
                        }
                    }
                }
            }

            $result = (new BuildXiOptimizer)->optimize($players, $formations, $budget, 100_000, 'max_return');
            $this->assertEqualsWithDelta($best, $result['profit'], 0.5, "round {$round}");
        }
    }
}
