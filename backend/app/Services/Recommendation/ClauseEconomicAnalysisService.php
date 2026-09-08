<?php

namespace App\Services\Recommendation;

use App\Services\Recommendation\ValueObjects\ClauseEconomicAnalysis;
use InvalidArgumentException;

/**
 * Answers exactly one question, as rigorously as the data allows: if I pay
 * this clause today, will the player's own value growth repay the premium?
 * Nothing sporting, nothing about squad fit — see ClauseEconomicAnalysis's
 * docblock for the output shape and ClauseOpportunityService for how this
 * gets combined with roster context (lock state, ownership, Fantasy Score).
 *
 * The model, in order:
 * 1. Compound daily growth rates over the last 1/3/7 days (not simple %
 *    change — a 3% move on a 20M€ player is a much stronger signal than the
 *    same 3% on a 5M€ one only once expressed as a rate).
 * 2. A blended `expectedDailyGrowth` from those three rates, weighted by
 *    config('fantasy.clause_analysis.trend_weights') — renormalized over
 *    whichever windows actually have data, since early in the season (or
 *    just after connecting an account) a 7-day-old snapshot often doesn't
 *    exist yet.
 * 3. A decayed, compounded projection (MarketValueProjector) — never a
 *    linear one — for 3/14/7 days out.
 * 4. Profit/ROI against the full clause price (not just the premium: paying
 *    the clause is the whole cost of the operation).
 * 5. A day-by-day break-even simulation (also decayed, not a closed-form
 *    log formula, since the decay makes the daily rate itself change over
 *    the simulated horizon).
 * 6. A 0-100 score from ROI(14d) shaped by how soon break-even happens, and
 *    a classification/`economicRecommendation` off that score.
 */
class ClauseEconomicAnalysisService
{
    private const ROI_FLOOR = -0.10;

    private const ROI_CEILING = 0.10;

    /** @var array<int, array{maxDays: int, factor: float}> ordered ascending by maxDays; the last entry catches everything beyond */
    private const BREAK_EVEN_FACTOR_BANDS = [
        ['maxDays' => 5, 'factor' => 1.00],
        ['maxDays' => 10, 'factor' => 0.90],
        ['maxDays' => 14, 'factor' => 0.75],
        ['maxDays' => PHP_INT_MAX, 'factor' => 0.50],
    ];

    private readonly array $trendWeights;

    private readonly int $maxBreakEvenDays;

    private readonly PlayerValueTrendCalculator $growthCalculator;

    /**
     * @param  array<string, float>|null  $trendWeights  keys '1d'/'3d'/'7d', must sum to 1.0 — defaults to config('fantasy.clause_analysis.trend_weights')
     */
    public function __construct(
        private readonly MarketValueProjector $projector,
        ?array $trendWeights = null,
        ?int $maxBreakEvenDays = null,
        ?PlayerValueTrendCalculator $growthCalculator = null,
    ) {
        $weights = $trendWeights ?? config('fantasy.clause_analysis.trend_weights');
        $this->assertWeightsSumToOne($weights);
        $this->trendWeights = $weights;
        $this->maxBreakEvenDays = $maxBreakEvenDays ?? config('fantasy.clause_analysis.max_break_even_days');
        $this->growthCalculator = $growthCalculator ?? new PlayerValueTrendCalculator;
    }

    public function analyze(
        float $marketValue,
        float $clauseValue,
        ?float $value1DayAgo,
        ?float $value3DaysAgo,
        ?float $value7DaysAgo,
    ): ClauseEconomicAnalysis {
        $change1d = $value1DayAgo !== null ? $marketValue - $value1DayAgo : null;
        $change3d = $value3DaysAgo !== null ? $marketValue - $value3DaysAgo : null;
        $change7d = $value7DaysAgo !== null ? $marketValue - $value7DaysAgo : null;

        $growth1d = $this->growthCalculator->compoundGrowthRate($marketValue, $value1DayAgo, 1);
        $growth3d = $this->growthCalculator->compoundGrowthRate($marketValue, $value3DaysAgo, 3);
        $growth7d = $this->growthCalculator->compoundGrowthRate($marketValue, $value7DaysAgo, 7);

        $expectedDailyGrowth = $this->growthCalculator->blendedDailyGrowth($growth1d, $growth3d, $growth7d, $this->trendWeights);

        $expectedValue3d = $this->projector->project($marketValue, $expectedDailyGrowth, 3);
        $expectedValue7d = $this->projector->project($marketValue, $expectedDailyGrowth, 7);
        $expectedValue14d = $this->projector->project($marketValue, $expectedDailyGrowth, 14);

        $profit3d = $expectedValue3d - $clauseValue;
        $profit7d = $expectedValue7d - $clauseValue;
        $profit14d = $expectedValue14d - $clauseValue;

        $roi3d = $clauseValue > 0 ? $profit3d / $clauseValue : 0.0;
        $roi7d = $clauseValue > 0 ? $profit7d / $clauseValue : 0.0;
        $roi14d = $clauseValue > 0 ? $profit14d / $clauseValue : 0.0;

        $breakEvenDays = $this->breakEvenDays($marketValue, $clauseValue, $expectedDailyGrowth);

        $score = $this->clauseEconomicScore($roi14d, $breakEvenDays);

        return new ClauseEconomicAnalysis(
            marketValue: $marketValue,
            clauseValue: $clauseValue,
            clausePremium: $clauseValue - $marketValue,
            clausePremiumPct: $marketValue > 0 ? ($clauseValue - $marketValue) / $marketValue : 0.0,
            change1d: $change1d,
            change3d: $change3d,
            change7d: $change7d,
            growth1d: $growth1d,
            growth3d: $growth3d,
            growth7d: $growth7d,
            expectedDailyGrowth: $expectedDailyGrowth,
            expectedValue3d: $expectedValue3d,
            expectedValue7d: $expectedValue7d,
            expectedValue14d: $expectedValue14d,
            profit3d: $profit3d,
            profit7d: $profit7d,
            profit14d: $profit14d,
            roi3d: $roi3d,
            roi7d: $roi7d,
            roi14d: $roi14d,
            breakEvenDays: $breakEvenDays,
            clauseEconomicScore: $score,
            classification: $this->classification($score),
            economicRecommendation: $this->economicRecommendation($score),
        );
    }

    /**
     * Day-by-day simulation, delegated to MarketValueProjector::daysToReach()
     * so this and MarketBuyAnalysisService share one break-even simulator —
     * see its docblock for the two shortcuts (already-there, and
     * mathematically-unreachable) applied before simulating.
     */
    private function breakEvenDays(float $marketValue, float $clauseValue, float $expectedDailyGrowth): ?int
    {
        return $this->projector->daysToReach($marketValue, $clauseValue, $expectedDailyGrowth, $this->maxBreakEvenDays);
    }

    /**
     * roi14d mapped onto [ROI_FLOOR, ROI_CEILING] -> [0, 100], clamped, then
     * scaled down the sooner break-even is *not* expected — a deal with a
     * great 14-day ROI that only breaks even on day 25 is a much weaker bet
     * than the same ROI landing in the first week.
     */
    private function clauseEconomicScore(float $roi14d, ?int $breakEvenDays): int
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

        foreach (self::BREAK_EVEN_FACTOR_BANDS as $band) {
            if ($breakEvenDays <= $band['maxDays']) {
                return $band['factor'];
            }
        }

        return 0.0; // unreachable — the last band's maxDays is PHP_INT_MAX
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

    private function economicRecommendation(int $score): string
    {
        return match (true) {
            $score >= 75 => 'PAY_CLAUSE',
            $score >= 60 => 'CONSIDER',
            $score >= 40 => 'WAIT',
            default => 'DO_NOT_PAY',
        };
    }

    private function assertWeightsSumToOne(array $weights): void
    {
        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException("fantasy.clause_analysis.trend_weights must sum to 1.0, got {$sum}.");
        }
    }
}
