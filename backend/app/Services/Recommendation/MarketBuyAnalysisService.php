<?php

namespace App\Services\Recommendation;

use App\Services\Recommendation\ValueObjects\MarketBuyAnalysis;
use InvalidArgumentException;

/**
 * Answers exactly one question, as rigorously as the data allows: if I buy
 * this player today at this price, will his own value growth pay it back?
 * Nothing sporting, nothing about points/minutes/starter status/squad need —
 * see MarketBuyAnalysis's docblock for the output shape. This is the market
 * mirror of ClauseEconomicAnalysisService and deliberately shares its model
 * rather than re-deriving it:
 * 1. Compound daily growth rates over the last 1/3/7 days and their blend
 *    into `expectedDailyGrowth` — PlayerValueTrendCalculator, the same
 *    calculator ClauseEconomicAnalysisService uses.
 * 2. A decayed, compounded projection for 3/7/14 days out —
 *    MarketValueProjector, the same projector.
 * 3. Profit/ROI against the *acquisition price* (not the current market
 *    value: the price actually paid is the whole cost of the operation, and
 *    can be above or below market value).
 * 4. A day-by-day break-even simulation — MarketValueProjector::daysToReach(),
 *    the same break-even simulator clauses use.
 * 5. A 0-100 score from ROI(14d) shaped by how soon break-even happens (its
 *    own bands — a market bid competes against other buyers today, so a
 *    slow payoff is a weaker bet here than the equivalent clause), and a
 *    classification/`recommendation` off that score.
 * 6. MaxBid (the highest price that still clears a minimum required ROI) and
 *    RecommendedBid (this league's own historical winning-bid premium
 *    applied to today's market value, capped at MaxBid — never above it,
 *    however many people are bidding).
 */
class MarketBuyAnalysisService
{
    private const ROI_FLOOR = -0.10;

    private const ROI_CEILING = 0.10;

    private readonly array $trendWeights;

    private readonly int $maxBreakEvenDays;

    private readonly array $breakEvenFactorBands;

    private readonly float $breakEvenFactorBeyond;

    private readonly float $requiredROI;

    private readonly PlayerValueTrendCalculator $growthCalculator;

    /**
     * @param  array<string, float>|null  $trendWeights  keys '1d'/'3d'/'7d', must sum to 1.0 — defaults to config('fantasy.clause_analysis.trend_weights'), the same weights clauses use
     * @param  array<int, array{maxDays: int, factor: float}>|null  $breakEvenFactorBands  ordered ascending by maxDays — defaults to config('fantasy.market_buy_analysis.break_even_factor_bands')
     */
    public function __construct(
        private readonly MarketValueProjector $projector,
        ?array $trendWeights = null,
        ?int $maxBreakEvenDays = null,
        ?array $breakEvenFactorBands = null,
        ?float $breakEvenFactorBeyond = null,
        ?float $requiredROI = null,
        ?PlayerValueTrendCalculator $growthCalculator = null,
    ) {
        $weights = $trendWeights ?? config('fantasy.clause_analysis.trend_weights');
        $this->assertWeightsSumToOne($weights);
        $this->trendWeights = $weights;
        $this->maxBreakEvenDays = $maxBreakEvenDays ?? config('fantasy.clause_analysis.max_break_even_days');
        $this->breakEvenFactorBands = $breakEvenFactorBands ?? config('fantasy.market_buy_analysis.break_even_factor_bands');
        $this->breakEvenFactorBeyond = $breakEvenFactorBeyond ?? config('fantasy.market_buy_analysis.break_even_factor_beyond');
        $this->requiredROI = $requiredROI ?? config('fantasy.market_buy_analysis.required_roi');
        $this->growthCalculator = $growthCalculator ?? new PlayerValueTrendCalculator;
    }

    public function analyze(
        float $currentMarketValue,
        float $acquisitionPrice,
        ?float $value1DayAgo,
        ?float $value3DaysAgo,
        ?float $value7DaysAgo,
        ?int $bidCount,
        float $expectedWinningPremium,
        string $auctionHistorySource,
    ): MarketBuyAnalysis {
        $growth1d = $this->growthCalculator->compoundGrowthRate($currentMarketValue, $value1DayAgo, 1);
        $growth3d = $this->growthCalculator->compoundGrowthRate($currentMarketValue, $value3DaysAgo, 3);
        $growth7d = $this->growthCalculator->compoundGrowthRate($currentMarketValue, $value7DaysAgo, 7);

        $expectedDailyGrowth = $this->growthCalculator->blendedDailyGrowth($growth1d, $growth3d, $growth7d, $this->trendWeights);

        $projectedValue3d = $this->projector->project($currentMarketValue, $expectedDailyGrowth, 3);
        $projectedValue7d = $this->projector->project($currentMarketValue, $expectedDailyGrowth, 7);
        $projectedValue14d = $this->projector->project($currentMarketValue, $expectedDailyGrowth, 14);

        $profit3d = $projectedValue3d - $acquisitionPrice;
        $profit7d = $projectedValue7d - $acquisitionPrice;
        $profit14d = $projectedValue14d - $acquisitionPrice;

        $roi3d = $acquisitionPrice > 0 ? $profit3d / $acquisitionPrice : 0.0;
        $roi7d = $acquisitionPrice > 0 ? $profit7d / $acquisitionPrice : 0.0;
        $roi14d = $acquisitionPrice > 0 ? $profit14d / $acquisitionPrice : 0.0;

        // When does the *current market value*, compounding forward, first
        // reach the acquisition price — mirrors ClauseEconomicAnalysisService's
        // breakEvenDays(marketValue, clauseValue, ...), acquisitionPrice
        // standing in for clauseValue.
        $breakEvenDays = $this->projector->daysToReach($currentMarketValue, $acquisitionPrice, $expectedDailyGrowth, $this->maxBreakEvenDays);

        $score = $this->buyEconomicScore($roi14d, $breakEvenDays);

        $maxBid = $projectedValue14d / (1 + $this->requiredROI);
        $estimatedWinningBid = $currentMarketValue * (1 + $expectedWinningPremium);
        $recommendedBid = $estimatedWinningBid <= $maxBid ? $estimatedWinningBid : 'DO_NOT_CHASE';

        $dataQuality = $this->growthCalculator->dataQuality($growth1d, $growth3d, $growth7d);

        return new MarketBuyAnalysis(
            currentMarketValue: $currentMarketValue,
            acquisitionPrice: $acquisitionPrice,
            growth1d: $growth1d,
            growth3d: $growth3d,
            growth7d: $growth7d,
            expectedDailyGrowth: $expectedDailyGrowth,
            projectedValue3d: $projectedValue3d,
            projectedValue7d: $projectedValue7d,
            projectedValue14d: $projectedValue14d,
            expectedProfit3d: $profit3d,
            expectedProfit7d: $profit7d,
            expectedProfit14d: $profit14d,
            expectedROI3d: $roi3d,
            expectedROI7d: $roi7d,
            expectedROI14d: $roi14d,
            breakEvenDays: $breakEvenDays,
            buyEconomicScore: $score,
            classification: $this->classification($score),
            recommendation: $this->recommendation($score),
            maxBid: $maxBid,
            recommendedBid: $recommendedBid,
            bidCount: $bidCount,
            expectedWinningPremium: $expectedWinningPremium,
            estimatedWinningBid: $estimatedWinningBid,
            auctionHistorySource: $auctionHistorySource,
            dataQuality: $dataQuality,
        );
    }

    /**
     * roi14d mapped onto [ROI_FLOOR, ROI_CEILING] -> [0, 100], clamped, then
     * scaled down the further out break-even lands — same shape as
     * ClauseEconomicAnalysisService::clauseEconomicScore(), own bands (see
     * breakEvenFactor()).
     */
    private function buyEconomicScore(float $roi14d, ?int $breakEvenDays): int
    {
        $roiScore = match (true) {
            $roi14d <= self::ROI_FLOOR => 0.0,
            $roi14d >= self::ROI_CEILING => 100.0,
            default => (($roi14d - self::ROI_FLOOR) / (self::ROI_CEILING - self::ROI_FLOOR)) * 100,
        };

        $breakEvenFactor = $this->breakEvenFactor($breakEvenDays);

        return (int) max(0, min(100, round($roiScore * $breakEvenFactor)));
    }

    private function breakEvenFactor(?int $breakEvenDays): float
    {
        if ($breakEvenDays === null) {
            return 0.0;
        }

        foreach ($this->breakEvenFactorBands as $band) {
            if ($breakEvenDays <= $band['maxDays']) {
                return $band['factor'];
            }
        }

        return $this->breakEvenFactorBeyond;
    }

    private function classification(int $score): string
    {
        return match (true) {
            $score >= 90 => 'EXCEPTIONAL',
            $score >= 75 => 'VERY_GOOD',
            $score >= 60 => 'GOOD',
            $score >= 40 => 'NEUTRAL',
            $score >= 20 => 'BAD',
            default => 'VERY_BAD',
        };
    }

    private function recommendation(int $score): string
    {
        return match (true) {
            $score >= 75 => 'BUY',
            $score >= 60 => 'CONSIDER',
            $score >= 40 => 'WAIT',
            default => 'DO_NOT_BUY',
        };
    }

    private function assertWeightsSumToOne(array $weights): void
    {
        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException("market buy analysis trend weights must sum to 1.0, got {$sum}.");
        }
    }
}
