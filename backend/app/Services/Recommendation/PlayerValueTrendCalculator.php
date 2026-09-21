<?php

namespace App\Services\Recommendation;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use Illuminate\Support\Carbon;

/**
 * The compound daily growth rate a player's value has been moving at over
 * the last 1/3/7 days, and the historical values that feed it — the one
 * piece of math every purely-economic engine in this app needs (clause
 * pay/no-pay, market buy/no-buy, ...). Extracted so those engines read the
 * exact same numbers off the exact same math instead of each keeping its
 * own copy that can silently drift.
 */
class PlayerValueTrendCalculator
{
    /**
     * A real LaLiga player's market value never plausibly multiplies or
     * divides by more than this in a week — futbolfantasy.com matches
     * players by scraped name only (PlayerNameMatcher), and an occasional
     * false match pulls in a completely different, unrelated player's
     * value. A ~10x-off historical reference doesn't just produce a wrong
     * percentage (as it would on Market/Team's simple % change): it feeds a
     * *compound* daily growth rate that a multi-day projection then blows up
     * into an absurd figure. See historicalValues().
     */
    private const PLAUSIBLE_RATIO_BOUNDS = [1 / 6, 6];

    /**
     * Values as of 1/3/7 days ago, preferring our own fantasy_player_snapshots
     * (real observed data) and falling back to futbolfantasy's day-offset
     * values only for whichever windows our own history doesn't cover yet —
     * per-window, not all-or-nothing, so a freshly-connected account still
     * gets a real (if externally-sourced) growth1d even before it has 7 real
     * days of its own history. External fallback values are sanity-checked
     * against the current value (see PLAUSIBLE_RATIO_BOUNDS) before use —
     * our own snapshots need no such check, they're the same player by
     * construction.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float} value 1d/3d/7d ago
     */
    public function historicalValues(FantasyPlayer $player, float $currentValue, ?FantasyExternalTrend $externalTrend): array
    {
        $now = now();
        $snapshots = $player->snapshots()
            ->whereNotNull('market_value')
            ->where('captured_at', '>=', $now->copy()->subDays(10))
            ->orderBy('captured_at')
            ->get(['market_value', 'captured_at']);

        $closestBefore = function (Carbon $target) use ($snapshots): ?float {
            $snapshot = $snapshots->filter(fn (FantasyPlayerSnapshot $s) => $s->captured_at->lessThanOrEqualTo($target))->last();

            return $snapshot?->market_value !== null ? (float) $snapshot->market_value : null;
        };

        $plausibleExternal = function (?int $value) use ($currentValue): ?float {
            if ($value === null || $value <= 0) {
                return null;
            }

            return $this->isPlausible($currentValue, (float) $value) ? (float) $value : null;
        };

        return [
            $closestBefore($now->copy()->subDay()) ?? $plausibleExternal($externalTrend?->value_1d),
            $closestBefore($now->copy()->subDays(3)) ?? $plausibleExternal($externalTrend?->value_3d),
            $closestBefore($now->copy()->subDays(7)) ?? $plausibleExternal($externalTrend?->value_7d),
        ];
    }

    /**
     * Values as of 1/3/7 days ago straight from futbolfantasy, never mixed
     * with our own snapshots — for screens where FF is the declared source of
     * economic history (Trading). Same plausibility guard as
     * historicalValues(), measured against FF's own current value so a
     * false name-match can't feed a compound growth rate. A window FF doesn't
     * have (or that fails the guard) is null, never 0.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float} value 1d/3d/7d ago
     */
    public function externalHistoricalValues(FantasyExternalTrend $externalTrend, float $currentValue): array
    {
        $plausible = function (?int $value) use ($currentValue): ?float {
            if ($value === null || $value <= 0) {
                return null;
            }

            return $this->isPlausible($currentValue, (float) $value) ? (float) $value : null;
        };

        return [$plausible($externalTrend->value_1d), $plausible($externalTrend->value_3d), $plausible($externalTrend->value_7d)];
    }

    /**
     * (currentValue / valueNDaysAgo)^(1/n) - 1 — the constant daily rate
     * that, compounded for n days, reproduces the observed change. Using
     * this instead of a simple %/n keeps 1d/3d/7d rates on the same footing
     * so they can be safely blended and compounded further.
     */
    public function compoundGrowthRate(float $currentValue, ?float $valueNDaysAgo, int $days): ?float
    {
        if ($valueNDaysAgo === null || $valueNDaysAgo <= 0 || $currentValue <= 0) {
            return null;
        }

        return ($currentValue / $valueNDaysAgo) ** (1 / $days) - 1;
    }

    /**
     * Weighted blend of the three growth rates, renormalized over whichever
     * are actually available (no snapshot 7 real days old yet is the norm
     * for a freshly-connected account, not the exception) so the configured
     * *relative* weights are still honored instead of silently treating a
     * missing window as 0% growth. No data at all means no assumed growth.
     *
     * @param  array<string, float>  $weights  keys '1d'/'3d'/'7d'
     */
    public function blendedDailyGrowth(?float $growth1d, ?float $growth3d, ?float $growth7d, array $weights): float
    {
        $available = array_filter([
            '1d' => $growth1d,
            '3d' => $growth3d,
            '7d' => $growth7d,
        ], fn (?float $v) => $v !== null);

        if (empty($available)) {
            return 0.0;
        }

        $totalWeight = array_sum(array_intersect_key($weights, $available));

        if ($totalWeight <= 0) {
            return 0.0;
        }

        $weightedSum = array_sum(array_map(fn ($key, $value) => $value * $weights[$key], array_keys($available), $available));

        return $weightedSum / $totalWeight;
    }

    /**
     * How much of the 1d/3d/7d growth-rate picture is actually available —
     * e.g. a missing 7d window (common for a freshly-listed or
     * freshly-connected-account player) lowers this rather than silently
     * scoring as confidently as a player with the full picture. Shared by
     * every caller that needs a data-quality/confidence figure for these
     * three windows, so there's exactly one such calculation in the app.
     */
    public function dataQuality(?float $growth1d, ?float $growth3d, ?float $growth7d): int
    {
        $available = count(array_filter([$growth1d, $growth3d, $growth7d], fn (?float $v) => $v !== null));

        return (int) round(($available / 3) * 100);
    }

    private function isPlausible(float $currentValue, float $historicalValue): bool
    {
        if ($currentValue <= 0 || $historicalValue <= 0) {
            return false;
        }

        $ratio = $currentValue / $historicalValue;

        return $ratio >= self::PLAUSIBLE_RATIO_BOUNDS[0] && $ratio <= self::PLAUSIBLE_RATIO_BOUNDS[1];
    }
}
