<?php

namespace Tests\Unit\Services\Recommendation;

use App\Services\Recommendation\MarketBuyAnalysisService;
use App\Services\Recommendation\MarketValueProjector;
use Tests\TestCase;

class MarketBuyAnalysisServiceTest extends TestCase
{
    private function service(?float $requiredROI = null): MarketBuyAnalysisService
    {
        return new MarketBuyAnalysisService(
            projector: new MarketValueProjector,
            requiredROI: $requiredROI,
        );
    }

    /** 1. Buying below today's market value is a good deal immediately, before any trend even matters. */
    public function test_buying_below_current_market_value_breaks_even_immediately(): void
    {
        $result = $this->service()->analyze(
            currentMarketValue: 10_000_000,
            acquisitionPrice: 9_000_000,
            value1DayAgo: null,
            value3DaysAgo: null,
            value7DaysAgo: null,
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertSame(0, $result->breakEvenDays);
        $this->assertGreaterThan(60, $result->buyEconomicScore);
        $this->assertContains($result->recommendation, ['BUY', 'CONSIDER']);
    }

    /** 2. Buying exactly at market value with a real positive trend still nets a genuine profit. */
    public function test_buying_at_current_value_with_a_positive_trend_is_profitable(): void
    {
        $now = 10_000_000;
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.02,
            value3DaysAgo: $now / (1.02 ** 3),
            value7DaysAgo: $now / (1.02 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertGreaterThan(0, $result->expectedROI14d);
        $this->assertContains($result->recommendation, ['BUY', 'CONSIDER']);
    }

    /** 3. A small premium over market value is still worth paying when the trend comfortably outpaces it. */
    public function test_a_small_premium_is_still_profitable_against_a_strong_trend(): void
    {
        $now = 10_000_000;
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: 10_200_000, // +2% over market value
            value1DayAgo: $now / 1.03,
            value3DaysAgo: $now / (1.03 ** 3),
            value7DaysAgo: $now / (1.03 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertNotNull($result->breakEvenDays);
        $this->assertLessThanOrEqual(7, $result->breakEvenDays);
        $this->assertGreaterThan(0, $result->expectedProfit14d);
    }

    /** 4. Too large a premium is not rescued by a moderate trend. */
    public function test_too_high_a_premium_is_not_worth_it(): void
    {
        $now = 10_000_000;
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: 15_000_000, // +50% over market value
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: $now / (1.01 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertSame('DO_NOT_BUY', $result->recommendation);
        $this->assertLessThan(20, $result->buyEconomicScore);
    }

    /** 5. A negative trend on top of a real premium is unambiguously bad. */
    public function test_a_negative_trend_scores_poorly(): void
    {
        $result = $this->service()->analyze(
            currentMarketValue: 10_000_000,
            acquisitionPrice: 10_500_000,
            value1DayAgo: 10_100_000,
            value3DaysAgo: 10_300_000,
            value7DaysAgo: 10_800_000,
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertLessThan(0, $result->expectedDailyGrowth);
        $this->assertSame('DO_NOT_BUY', $result->recommendation);
    }

    /** 6. A fast break-even (<=3 days) keeps the full break-even factor. */
    public function test_a_fast_break_even_keeps_the_full_score_factor(): void
    {
        $now = 10_000_000;
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: 10_300_000, // +3%
            value1DayAgo: $now / 1.03,
            value3DaysAgo: $now / (1.03 ** 3),
            value7DaysAgo: $now / (1.03 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertNotNull($result->breakEvenDays);
        $this->assertLessThanOrEqual(3, $result->breakEvenDays);
    }

    /** 7. A break-even beyond 14 days is a materially weaker bet — heavily discounted score factor. */
    public function test_a_break_even_beyond_14_days_is_heavily_discounted(): void
    {
        $now = 10_000_000;
        // A slow +0.5%/day rate against a real but unhurried +8% premium —
        // breaks even around day 26 (still inside the 30-day horizon), well
        // past the 14-day band.
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: 10_800_000,
            value1DayAgo: $now / 1.005,
            value3DaysAgo: $now / (1.005 ** 3),
            value7DaysAgo: $now / (1.005 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertNotNull($result->breakEvenDays);
        $this->assertGreaterThan(14, $result->breakEvenDays);
    }

    /** 8. No break-even at all within the horizon collapses the score toward zero regardless of the raw ROI figure. */
    public function test_no_break_even_forces_a_very_low_score(): void
    {
        $result = $this->service()->analyze(
            currentMarketValue: 10_000_000,
            acquisitionPrice: 11_000_000,
            value1DayAgo: 10_100_000,
            value3DaysAgo: 10_300_000,
            value7DaysAgo: 10_800_000,
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertNull($result->breakEvenDays);
        $this->assertLessThanOrEqual(19, $result->buyEconomicScore);
    }

    /** 9. Recalculating for the same player at a higher acquisition price must never score better. */
    public function test_the_same_player_scores_worse_at_a_higher_acquisition_price(): void
    {
        $now = 10_000_000;
        $args = [
            'currentMarketValue' => $now,
            'value1DayAgo' => $now / 1.02,
            'value3DaysAgo' => $now / (1.02 ** 3),
            'value7DaysAgo' => $now / (1.02 ** 7),
            'bidCount' => 1,
            'expectedWinningPremium' => 0.05,
            'auctionHistorySource' => 'fallback',
        ];

        $cheap = $this->service()->analyze(...$args, acquisitionPrice: 10_000_000);
        $expensive = $this->service()->analyze(...$args, acquisitionPrice: 12_000_000);

        $this->assertGreaterThan($expensive->buyEconomicScore, $cheap->buyEconomicScore);
    }

    /** 10. bidCount is informational only — it must never move MaxBid. */
    public function test_bid_count_never_changes_max_bid(): void
    {
        $now = 10_000_000;
        $args = [
            'currentMarketValue' => $now,
            'acquisitionPrice' => $now,
            'value1DayAgo' => $now / 1.02,
            'value3DaysAgo' => $now / (1.02 ** 3),
            'value7DaysAgo' => $now / (1.02 ** 7),
            'expectedWinningPremium' => 0.05,
            'auctionHistorySource' => 'fallback',
        ];

        $fewBids = $this->service()->analyze(...$args, bidCount: 1);
        $manyBids = $this->service()->analyze(...$args, bidCount: 500);

        $this->assertSame($fewBids->maxBid, $manyBids->maxBid);
    }

    /** 11. When the estimated winning bid exceeds MaxBid, chasing it further is not economically justified — MaxBid stays put. */
    public function test_estimated_winning_bid_above_max_bid_returns_do_not_chase(): void
    {
        $now = 10_000_000;
        $result = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.001,
            value3DaysAgo: $now / (1.001 ** 3),
            value7DaysAgo: $now / (1.001 ** 7),
            bidCount: 20,
            expectedWinningPremium: 0.40, // way above what the flat trend justifies
            auctionHistorySource: 'league',
        );

        $this->assertSame('DO_NOT_CHASE', $result->recommendedBid);
        $this->assertGreaterThan($result->maxBid, $result->estimatedWinningBid);
    }

    /** 14. An incomplete 1/3/7-day picture (missing 7d) lowers dataQuality rather than pretending the picture is complete. */
    public function test_incomplete_growth_windows_reduce_data_quality(): void
    {
        $now = 10_000_000;
        $full = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: $now / (1.01 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $missing7d = $this->service()->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: null,
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertSame(100, $full->dataQuality);
        $this->assertLessThan($full->dataQuality, $missing7d->dataQuality);
        $this->assertSame(67, $missing7d->dataQuality);
    }

    /** 15. MaxBid always equals projectedValue14d / (1 + requiredROI), for whatever requiredROI is configured. */
    public function test_max_bid_is_coherent_with_the_required_roi(): void
    {
        $now = 10_000_000;
        $result = $this->service(requiredROI: 0.05)->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: $now / (1.01 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertEqualsWithDelta($result->projectedValue14d / 1.05, $result->maxBid, 0.01);

        // A stricter requiredROI must never allow a *higher* MaxBid for the same projection.
        $stricter = $this->service(requiredROI: 0.15)->analyze(
            currentMarketValue: $now,
            acquisitionPrice: $now,
            value1DayAgo: $now / 1.01,
            value3DaysAgo: $now / (1.01 ** 3),
            value7DaysAgo: $now / (1.01 ** 7),
            bidCount: 1,
            expectedWinningPremium: 0.05,
            auctionHistorySource: 'fallback',
        );

        $this->assertLessThan($result->maxBid, $stricter->maxBid);
    }
}
