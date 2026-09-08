<?php

namespace App\Services\Recommendation\ValueObjects;

/**
 * The full output of ClauseEconomicAnalysisService::analyze() for one
 * player: is paying this clause today likely to pay for itself through the
 * player's own value growth? Every percentage here (`clausePremiumPct`,
 * `growth*`, `expectedDailyGrowth`, `roi*`) is stored as a decimal fraction
 * (0.125, not 12.5) — the UI multiplies by 100 when formatting, same
 * convention the spec asked for so a raw dump of this object is never
 * ambiguous about scale.
 */
class ClauseEconomicAnalysis
{
    public function __construct(
        public readonly float $marketValue,
        public readonly float $clauseValue,
        public readonly float $clausePremium,
        public readonly float $clausePremiumPct,
        public readonly ?float $change1d,
        public readonly ?float $change3d,
        public readonly ?float $change7d,
        public readonly ?float $growth1d,
        public readonly ?float $growth3d,
        public readonly ?float $growth7d,
        public readonly float $expectedDailyGrowth,
        public readonly float $expectedValue3d,
        public readonly float $expectedValue7d,
        public readonly float $expectedValue14d,
        public readonly float $profit3d,
        public readonly float $profit7d,
        public readonly float $profit14d,
        public readonly float $roi3d,
        public readonly float $roi7d,
        public readonly float $roi14d,
        public readonly ?int $breakEvenDays,
        public readonly int $clauseEconomicScore,
        public readonly string $classification,
        public readonly string $economicRecommendation,
    ) {}

    public function toArray(): array
    {
        return [
            'marketValue' => $this->marketValue,
            'clauseValue' => $this->clauseValue,
            'clausePremium' => $this->clausePremium,
            'clausePremiumPct' => $this->clausePremiumPct,
            'change1d' => $this->change1d,
            'change3d' => $this->change3d,
            'change7d' => $this->change7d,
            'growth1d' => $this->growth1d,
            'growth3d' => $this->growth3d,
            'growth7d' => $this->growth7d,
            'expectedDailyGrowth' => $this->expectedDailyGrowth,
            'expectedValue3d' => $this->expectedValue3d,
            'expectedValue7d' => $this->expectedValue7d,
            'expectedValue14d' => $this->expectedValue14d,
            'profit3d' => $this->profit3d,
            'profit7d' => $this->profit7d,
            'profit14d' => $this->profit14d,
            'roi3d' => $this->roi3d,
            'roi7d' => $this->roi7d,
            'roi14d' => $this->roi14d,
            'breakEvenDays' => $this->breakEvenDays,
            'clauseEconomicScore' => $this->clauseEconomicScore,
            'classification' => $this->classification,
            'economicRecommendation' => $this->economicRecommendation,
        ];
    }
}
