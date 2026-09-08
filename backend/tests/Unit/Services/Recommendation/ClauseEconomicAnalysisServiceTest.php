<?php

namespace Tests\Unit\Services\Recommendation;

use App\Services\Recommendation\ClauseEconomicAnalysisService;
use App\Services\Recommendation\MarketValueProjector;
use InvalidArgumentException;
use Tests\TestCase;

class ClauseEconomicAnalysisServiceTest extends TestCase
{
    private function service(?array $trendWeights = null, ?int $maxBreakEvenDays = null): ClauseEconomicAnalysisService
    {
        return new ClauseEconomicAnalysisService(new MarketValueProjector, $trendWeights, $maxBreakEvenDays);
    }

    public function test_computes_the_worked_example_from_the_spec(): void
    {
        // marketValue = 10M, clauseValue = 12M -> premium = 2M / 20%.
        $result = $this->service()->analyze(10_000_000, 12_000_000, null, null, null);

        $this->assertSame(2_000_000.0, $result->clausePremium);
        $this->assertEqualsWithDelta(0.20, $result->clausePremiumPct, 0.0001);
    }

    public function test_growth_rates_are_compound_not_simple_percentages(): void
    {
        // +10% over 3 days compounded daily is NOT 10%/3 per day.
        $result = $this->service()->analyze(
            marketValue: 11_000_000,
            clauseValue: 11_000_000,
            value1DayAgo: null,
            value3DaysAgo: 10_000_000,
            value7DaysAgo: null,
        );

        // (11/10)^(1/3) - 1 ≈ 0.03228
        $this->assertEqualsWithDelta(0.0323, $result->growth3d, 0.001);
    }

    public function test_a_smaller_absolute_move_on_a_cheaper_player_is_a_stronger_relative_signal(): void
    {
        // Both gain 150k in a day: 5M player (+3%) vs 20M player (+0.75%).
        $cheap = $this->service()->analyze(5_150_000, 5_150_000, 5_000_000, null, null);
        $expensive = $this->service()->analyze(20_150_000, 20_150_000, 20_000_000, null, null);

        $this->assertGreaterThan($expensive->growth1d, $cheap->growth1d);
        $this->assertEqualsWithDelta(0.03, $cheap->growth1d, 0.0001);
        $this->assertEqualsWithDelta(0.0075, $expensive->growth1d, 0.0001);
    }

    public function test_expected_daily_growth_is_weighted_across_windows_using_the_configured_weights(): void
    {
        $service = $this->service(trendWeights: ['1d' => 0.5, '3d' => 0.3, '7d' => 0.2]);

        // All three windows show the exact same +1%/day compound rate -> blended must also be +1%/day.
        $now = 10_000_000;
        $result = $service->analyze(
            marketValue: $now,
            clauseValue: $now,
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: $now / (1.01 ** 7),
        );

        $this->assertEqualsWithDelta(0.01, $result->expectedDailyGrowth, 0.0005);
    }

    public function test_renormalizes_weights_when_a_window_has_no_data(): void
    {
        // 1d and 3d both agree on a true +2%/day compound rate; 7d missing entirely.
        // Renormalizing over what's available should still land on +2%/day,
        // not drag the blend toward 0 just because a third of the weight has no data.
        $service = $this->service(trendWeights: ['1d' => 0.5, '3d' => 0.3, '7d' => 0.2]);
        $now = 10_200_000;

        $result = $service->analyze(
            marketValue: $now,
            clauseValue: $now,
            value1DayAgo: $now / 1.02,
            value3DaysAgo: $now / (1.02 ** 3),
            value7DaysAgo: null,
        );

        $this->assertEqualsWithDelta(0.02, $result->growth1d, 0.0001);
        $this->assertEqualsWithDelta(0.02, $result->growth3d, 0.0001);
        $this->assertEqualsWithDelta(0.02, $result->expectedDailyGrowth, 0.001);
    }

    public function test_no_historical_data_at_all_assumes_zero_growth_not_an_invented_number(): void
    {
        $result = $this->service()->analyze(10_000_000, 10_500_000, null, null, null);

        $this->assertSame(0.0, $result->expectedDailyGrowth);
        $this->assertSame(10_000_000.0, $result->expectedValue14d);
    }

    public function test_projection_uses_compound_decay_not_linear_extrapolation(): void
    {
        $result = $this->service()->analyze(10_000_000, 10_000_000, 9_900_000, 9_700_000, 9_300_000);

        // A naive linear model (marketValue + dailyIncrease * days) would produce
        // a much larger 14d figure than the decayed compound one.
        $naiveLinear14d = 10_000_000 + ($result->expectedDailyGrowth * 10_000_000 * 14);
        $this->assertLessThan($naiveLinear14d, $result->expectedValue14d);
    }

    public function test_break_even_is_zero_when_the_clause_is_at_or_below_market_value(): void
    {
        $result = $this->service()->analyze(10_000_000, 9_500_000, null, null, null);

        $this->assertSame(0, $result->breakEvenDays);
    }

    public function test_break_even_is_null_when_growth_is_negative_and_the_clause_costs_more_than_market_value(): void
    {
        $result = $this->service()->analyze(10_000_000, 11_000_000, 10_100_000, 10_300_000, 10_800_000);

        $this->assertLessThan(0, $result->expectedDailyGrowth);
        $this->assertNull($result->breakEvenDays);
        $this->assertSame('DO_NOT_PAY', $result->economicRecommendation);
        $this->assertLessThanOrEqual(19, $result->clauseEconomicScore);
    }

    public function test_break_even_finds_the_day_the_projected_value_first_reaches_the_clause(): void
    {
        // Flat +2%/day (all three windows agree), clause at +5% premium.
        $result = $this->service()->analyze(
            marketValue: 10_000_000,
            clauseValue: 10_500_000,
            value1DayAgo: 10_000_000 / 1.02,
            value3DaysAgo: 10_000_000 / (1.02 ** 3),
            value7DaysAgo: 10_000_000 / (1.02 ** 7),
        );

        $this->assertNotNull($result->breakEvenDays);
        // 1.02^3 ≈ 1.061 > 1.05, so day 3 (still full-strength decay) clears a 5% premium.
        $this->assertSame(3, $result->breakEvenDays);
    }

    public function test_score_and_recommendation_bands_match_the_spec_examples(): void
    {
        // roi14d exactly at -5% and +5% should score 25 and 75 respectively
        // when break-even lands well inside the "factor 1.0" band.
        $service = $this->service();

        // Falling: past values higher than today's. Rising: past values lower than today's.
        $negative = $service->analyze(10_000_000, 10_000_000, 10_050_000, 10_150_000, 10_350_000);
        $positive = $service->analyze(10_000_000, 10_000_000, 9_950_000, 9_850_000, 9_650_000);

        $this->assertLessThan(50, $negative->clauseEconomicScore);
        $this->assertGreaterThan(50, $positive->clauseEconomicScore);
    }

    public function test_classification_and_recommendation_thresholds(): void
    {
        $service = $this->service();

        $veryGood = $service->analyze(10_000_000, 9_000_000, 9_800_000, 9_500_000, 9_000_000);
        $this->assertContains($veryGood->classification, ['EXCEPTIONAL', 'VERY_GOOD', 'GOOD']);
        $this->assertContains($veryGood->economicRecommendation, ['PAY_CLAUSE', 'CONSIDER']);

        $veryBad = $service->analyze(10_000_000, 15_000_000, 10_100_000, 10_300_000, 10_800_000);
        $this->assertSame('VERY_BAD', $veryBad->classification);
        $this->assertSame('DO_NOT_PAY', $veryBad->economicRecommendation);
    }

    public function test_rejects_weights_that_do_not_sum_to_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service(trendWeights: ['1d' => 0.5, '3d' => 0.3, '7d' => 0.3]);
    }

    public function test_uses_the_configured_max_break_even_days(): void
    {
        // A very slow +0.1%/day rate on a huge premium won't break even within 5 simulated days.
        $service = $this->service(maxBreakEvenDays: 5);

        $result = $service->analyze(
            marketValue: 10_000_000,
            clauseValue: 15_000_000,
            value1DayAgo: 10_000_000 / 1.001,
            value3DaysAgo: 10_000_000 / (1.001 ** 3),
            value7DaysAgo: 10_000_000 / (1.001 ** 7),
        );

        $this->assertNull($result->breakEvenDays);
    }
}
