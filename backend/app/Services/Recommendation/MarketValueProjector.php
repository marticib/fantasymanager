<?php

namespace App\Services\Recommendation;

use InvalidArgumentException;

/**
 * Projects a market value forward day by day, compounding a daily growth
 * rate that decays over time rather than assuming today's pace holds
 * unchanged for the whole horizon (see config/fantasy.php `clause_analysis.
 * decay_bands`). Deliberately NOT `marketValue + dailyIncrease * days` —
 * that's a linear model and materially overstates multi-week projections
 * for anything but a perfectly flat trend.
 */
class MarketValueProjector
{
    /** @var array<int, array{from: int, to: int, factor: float}> */
    private readonly array $decayBands;

    /**
     * @param  array<int, array{from: int, to: int, factor: float}>|null  $decayBands  defaults to config('fantasy.clause_analysis.decay_bands')
     */
    public function __construct(?array $decayBands = null)
    {
        $bands = $decayBands ?? config('fantasy.clause_analysis.decay_bands');

        if (empty($bands)) {
            throw new InvalidArgumentException('MarketValueProjector needs at least one decay band.');
        }

        $this->decayBands = $bands;
    }

    /**
     * Compounds `$marketValue` forward `$days` days, applying `$dailyGrowth`
     * scaled by that day's decay factor at each step:
     * `value[d] = value[d-1] * (1 + dailyGrowth * decayFactor(d))`.
     */
    public function project(float $marketValue, float $dailyGrowth, int $days): float
    {
        $value = $marketValue;

        for ($day = 1; $day <= $days; $day++) {
            $value *= 1 + ($dailyGrowth * $this->decayFactor($day));
        }

        return $value;
    }

    /**
     * The configured factor for the band `$day` falls in. A day beyond the
     * last configured band's `to` keeps compounding at that band's factor —
     * the trend has already decayed to its slowest modeled pace, and there's
     * no signal to say it either stops or reverts to full strength.
     */
    public function decayFactor(int $day): float
    {
        foreach ($this->decayBands as $band) {
            if ($day >= $band['from'] && $day <= $band['to']) {
                return $band['factor'];
            }
        }

        $bands = $this->decayBands;

        return end($bands)['factor'];
    }

    /**
     * The first day (1..$maxDays) at which compounding `$dailyGrowth` (same
     * decayed model as project()) from `$currentValue` first reaches
     * `$targetValue` — a day-by-day simulation, not a closed-form formula,
     * since the decay bands change the effective rate partway through.
     * Shared by any "when does this pay for itself" calculation (clause
     * break-even, market buy break-even, ...) so there's exactly one
     * break-even simulator in the app, not one per caller. Two shortcuts
     * before simulating:
     * - `$targetValue` is already <= `$currentValue`: day 0, immediately met.
     * - `$dailyGrowth` <= 0 and `$targetValue` > `$currentValue`: mathematically
     *   unreachable (every decay factor is <= 1, so a non-positive rate never
     *   turns positive) — no need to simulate to find that out.
     */
    public function daysToReach(float $currentValue, float $targetValue, float $dailyGrowth, int $maxDays): ?int
    {
        if ($targetValue <= $currentValue) {
            return 0;
        }

        if ($dailyGrowth <= 0) {
            return null;
        }

        $value = $currentValue;

        for ($day = 1; $day <= $maxDays; $day++) {
            $value *= 1 + ($dailyGrowth * $this->decayFactor($day));

            if ($value >= $targetValue) {
                return $day;
            }
        }

        return null;
    }
}
