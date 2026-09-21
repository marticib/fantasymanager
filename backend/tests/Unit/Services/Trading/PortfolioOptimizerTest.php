<?php

namespace Tests\Unit\Services\Trading;

use App\Services\Trading\PortfolioOptimizer;
use PHPUnit\Framework\TestCase;

class PortfolioOptimizerTest extends TestCase
{
    private const STEP = 100_000;

    private function group(int $id, int $cost, float $profit, string $key = 'MARKET'): array
    {
        return ['playerId' => $id, 'options' => [['key' => $key, 'cost' => $cost, 'profit' => $profit]]];
    }

    private function ids(array $result): array
    {
        $ids = array_column($result['buys'], 'playerId');
        sort($ids);

        return $ids;
    }

    private function plan(array $groups, array $sells, int $capital): array
    {
        return (new PortfolioOptimizer)->optimize($groups, $sells, $capital, self::STEP);
    }

    public function test_no_candidates_means_an_empty_plan_not_an_error(): void
    {
        $result = $this->plan([], [], 20_000_000);

        $this->assertSame([], $result['buys']);
        $this->assertSame([], $result['sells']);
        $this->assertSame(0.0, $result['totalGain']);
    }

    public function test_no_capital_buys_nothing(): void
    {
        $this->assertSame([], $this->plan([$this->group(1, 5_000_000, 900_000)], [], 0)['buys']);
    }

    public function test_it_picks_the_combination_with_the_highest_absolute_profit_not_the_greedy_one(): void
    {
        // Greedy-by-ROI takes #1 (ROI 13.3%) and then nothing else fits (4M
        // left); the optimum is #2 + #3 = 1.1M > 0.8M.
        $result = $this->plan([
            $this->group(1, 6_000_000, 800_000),
            $this->group(2, 5_000_000, 600_000),
            $this->group(3, 5_000_000, 500_000),
        ], [], 10_000_000);

        $this->assertSame([2, 3], $this->ids($result));
        $this->assertEqualsWithDelta(1_100_000, $result['purchaseProfit'], 0.5);
    }

    public function test_absolute_profit_beats_a_higher_roi_on_a_smaller_ticket(): void
    {
        $result = $this->plan([
            $this->group(1, 1_000_000, 300_000),   // ROI 30%
            $this->group(2, 10_000_000, 1_500_000), // ROI 15%, far more absolute profit
        ], [], 10_000_000);

        $this->assertSame([2], $this->ids($result));
    }

    public function test_the_plan_never_exceeds_the_capital(): void
    {
        $groups = [];
        foreach ([[3_150_000, 500_000], [2_950_000, 450_000], [4_010_000, 700_000], [1_990_000, 200_000]] as $i => [$cost, $profit]) {
            $groups[] = $this->group($i + 1, $cost, $profit);
        }

        $result = $this->plan($groups, [], 9_000_000);
        $spent = 0;
        foreach ($result['buys'] as $buy) {
            $spent += $groups[$buy['playerId'] - 1]['options'][0]['cost'];
        }

        $this->assertLessThanOrEqual(9_000_000, $spent);
    }

    public function test_spending_exactly_all_the_capital_is_allowed(): void
    {
        $result = $this->plan([$this->group(1, 6_000_000, 500_000), $this->group(2, 4_000_000, 300_000)], [], 10_000_000);

        $this->assertSame([1, 2], $this->ids($result));
    }

    public function test_a_price_one_euro_over_the_capital_is_not_bought(): void
    {
        $this->assertSame([], $this->plan([$this->group(1, 10_000_001, 900_000)], [], 10_000_000)['buys']);
    }

    public function test_the_same_player_is_never_bought_twice_even_with_two_routes(): void
    {
        $group = ['playerId' => 7, 'options' => [
            ['key' => 'MARKET', 'cost' => 4_000_000, 'profit' => 500_000],
            ['key' => 'CLAUSE', 'cost' => 6_000_000, 'profit' => 800_000],
        ]];

        $result = $this->plan([$group], [], 50_000_000);

        $this->assertCount(1, $result['buys']);
        $this->assertSame('CLAUSE', $result['buys'][0]['key']); // plenty of capital: the better-profit route
    }

    public function test_market_and_clause_routes_compete_for_the_same_capital(): void
    {
        $two = ['playerId' => 1, 'options' => [
            ['key' => 'MARKET', 'cost' => 4_000_000, 'profit' => 500_000],
            ['key' => 'CLAUSE', 'cost' => 6_000_000, 'profit' => 800_000],
        ]];
        $other = $this->group(2, 5_000_000, 600_000, 'CLAUSE');

        // 8M: #1 market (4M) + #2 (5M) don't fit together; the best use of the
        // shared money is #1's clause route (6M, 800k).
        $result = $this->plan([$two, $other], [], 8_000_000);

        $this->assertCount(1, $result['buys']);
        $this->assertSame(['playerId' => 1, 'key' => 'CLAUSE'], $result['buys'][0]);
        $this->assertEqualsWithDelta(800_000, $result['purchaseProfit'], 0.5);
    }

    public function test_selling_a_player_can_fund_a_better_purchase(): void
    {
        // Only 2M cash; the 8M purchase earns 900k. Selling a player whose
        // hold-vs-sell value is -100k (forgone appreciation) for 7M pays off.
        $result = $this->plan(
            [$this->group(1, 8_000_000, 900_000)],
            [['playerId' => 50, 'sale' => 7_000_000, 'value' => -100_000.0]],
            2_000_000,
        );

        $this->assertSame([1], $this->ids($result));
        $this->assertSame([50], $result['sells']);
        $this->assertEqualsWithDelta(800_000, $result['totalGain'], 0.5);
    }

    public function test_a_sale_is_not_proposed_when_it_costs_more_than_the_purchase_earns(): void
    {
        $result = $this->plan(
            [$this->group(1, 8_000_000, 300_000)],
            [['playerId' => 50, 'sale' => 7_000_000, 'value' => -600_000.0]],
            2_000_000,
        );

        $this->assertSame([], $result['sells']);
        $this->assertSame([], $result['buys']);
    }

    public function test_a_falling_player_is_sold_to_avoid_the_loss_even_without_any_purchase(): void
    {
        $result = $this->plan([], [['playerId' => 9, 'sale' => 5_000_000, 'value' => 400_000.0]], 0);

        $this->assertSame([9], $result['sells']);
        $this->assertSame([], $result['buys']);
        $this->assertEqualsWithDelta(400_000, $result['saleValue'], 0.5);
    }

    public function test_sale_proceeds_are_not_counted_twice(): void
    {
        // Two 6M purchases, 2M cash, ONE 7M sale: only one purchase is affordable.
        $result = $this->plan(
            [$this->group(1, 6_000_000, 700_000), $this->group(2, 6_000_000, 650_000)],
            [['playerId' => 50, 'sale' => 7_000_000, 'value' => 0.0]],
            2_000_000,
        );

        $this->assertCount(1, $result['buys']);
    }

    public function test_the_same_input_always_gives_the_same_plan(): void
    {
        $groups = [$this->group(3, 3_000_000, 400_000), $this->group(1, 3_000_000, 400_000), $this->group(2, 3_000_000, 400_000)];

        $first = $this->plan($groups, [], 6_000_000);
        $second = $this->plan(array_reverse($groups), [], 6_000_000);

        $this->assertSame($first, $second);
    }

    public function test_it_matches_brute_force_on_random_small_instances(): void
    {
        mt_srand(42);

        for ($round = 0; $round < 25; $round++) {
            $n = mt_rand(3, 8);
            $groups = [];
            for ($i = 1; $i <= $n; $i++) {
                $groups[] = $this->group($i, mt_rand(10, 60) * 100_000, (float) (mt_rand(1, 20) * 50_000));
            }
            $capital = mt_rand(50, 200) * 100_000;

            $best = 0.0;
            for ($mask = 0; $mask < (1 << $n); $mask++) {
                $cost = 0;
                $profit = 0.0;
                for ($i = 0; $i < $n; $i++) {
                    if ($mask & (1 << $i)) {
                        $cost += $groups[$i]['options'][0]['cost'];
                        $profit += $groups[$i]['options'][0]['profit'];
                    }
                }
                if ($cost <= $capital) {
                    $best = max($best, $profit);
                }
            }

            $this->assertEqualsWithDelta($best, $this->plan($groups, [], $capital)['purchaseProfit'], 0.5, "round {$round}");
        }
    }
}
