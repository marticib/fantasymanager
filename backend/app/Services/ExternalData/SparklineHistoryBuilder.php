<?php

namespace App\Services\ExternalData;

use App\Models\FantasyExternalTrend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the point series behind a value-over-time chart/sparkline. Our own
 * fantasy_player_snapshots only starts accumulating the day an account
 * connects, so a freshly-connected account would see nothing but a flat
 * line for weeks. When our own history is too thin to say anything, this
 * falls back to futbolfantasy's day-offset absolute values — real scraped
 * numbers from the same unofficial source as the "7d ext." column, not
 * invented ones (see FutbolFantasySyncService).
 *
 * Defaults to the 7-day window (7/3/1 days ago + now) used by the Market/
 * Team/Clauses row sparkline, matching exactly what `pct_7d` compares —
 * intentionally NOT the full 14/30-day depth futbolfantasy also has there,
 * since a wider window would occasionally point the wrong color (e.g. a
 * player up over the last 7 days but still down against 30 days ago), which
 * would silently disagree with the "7d ext." percentage shown right next to
 * it in the same row. The player detail page's full chart has no such
 * adjacent 7-day text to stay consistent with, so it passes a wider
 * `$dayOffsets` explicitly.
 */
class SparklineHistoryBuilder
{
    private const MIN_OWN_DISTINCT_POINTS = 3;

    private const MIN_EXTERNAL_POINTS = 3;

    private const VALUE_FIELD_BY_DAYS_AGO = [
        1 => 'value_1d',
        3 => 'value_3d',
        7 => 'value_7d',
        14 => 'value_14d',
        30 => 'value_30d',
    ];

    /**
     * @param  Collection<int, array{value: int, capturedAt: string}>  $ownHistory  ascending by captured_at
     * @param  int[]  $dayOffsets  descending, e.g. [7, 3, 1] — each must be a key in VALUE_FIELD_BY_DAYS_AGO
     * @param  bool  $preferExternal  skip the "own wins once it clears the low 3-point bar" shortcut below —
     *                                used by the player detail page's full evolution chart, where "own" being
     *                                real doesn't mean it's *useful*: a freshly-connected account can clear
     *                                that bar with a few hours-apart points that all cluster at the very end
     *                                of a 30-day window, while futbolfantasy already has real daily-resolution
     *                                history covering the whole window. Still falls back to "own" if there's no
     *                                external trend at all, or too few external points to be worth showing.
     * @return array{0: array, 1: 'own'|'external'}
     */
    public function build(
        Collection $ownHistory,
        ?FantasyExternalTrend $externalTrend,
        int $currentValue,
        ?Carbon $now = null,
        array $dayOffsets = [7, 3, 1],
        bool $preferExternal = false,
    ): array {
        if (! $preferExternal && $ownHistory->pluck('value')->unique()->count() >= self::MIN_OWN_DISTINCT_POINTS) {
            return [$ownHistory->values()->all(), 'own'];
        }

        if (! $externalTrend) {
            return [$ownHistory->values()->all(), 'own'];
        }

        $now ??= now();
        $points = [];

        foreach ($dayOffsets as $daysAgo) {
            $field = self::VALUE_FIELD_BY_DAYS_AGO[$daysAgo] ?? null;
            $value = $field ? $externalTrend->{$field} : null;

            if ($value !== null) {
                $points[] = ['value' => $value, 'capturedAt' => $now->copy()->subDays($daysAgo)->toIso8601String()];
            }
        }

        $points[] = ['value' => $currentValue, 'capturedAt' => $now->toIso8601String()];

        return count($points) >= self::MIN_EXTERNAL_POINTS ? [$points, 'external'] : [$ownHistory->values()->all(), 'own'];
    }
}
