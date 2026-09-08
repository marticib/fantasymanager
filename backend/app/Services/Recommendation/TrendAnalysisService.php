<?php

namespace App\Services\Recommendation;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Services\Recommendation\ValueObjects\PlayerTrend;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes market-value velocity from fantasy_player_snapshots history.
 * Classification thresholds are percentage change over 3 days, since that is
 * long enough to smooth daily market noise while still being actionable.
 */
class TrendAnalysisService
{
    private const MOLT_ALCISTA_THRESHOLD = 8.0;

    private const ALCISTA_THRESHOLD = 3.0;

    private const BAIXISTA_THRESHOLD = -3.0;

    private const MOLT_BAIXISTA_THRESHOLD = -8.0;

    public function analyze(FantasyPlayer $player): PlayerTrend
    {
        $snapshots = $player->snapshots()
            ->whereNotNull('market_value')
            ->orderBy('captured_at')
            ->get(['market_value', 'captured_at']);

        return $this->analyzeSnapshots($snapshots);
    }

    public function analyzeSnapshots(Collection $snapshots): PlayerTrend
    {
        if ($snapshots->isEmpty()) {
            return new PlayerTrend(null, null, null, null, null, null, null, PlayerTrend::ESTABLE, 0);
        }

        $latest = $snapshots->last();
        $now = $latest->captured_at;

        $at24h = $this->closestBefore($snapshots, $now->copy()->subDay());
        $at48h = $this->closestBefore($snapshots, $now->copy()->subDays(2));
        $at3d = $this->closestBefore($snapshots, $now->copy()->subDays(3));
        $at7d = $this->closestBefore($snapshots, $now->copy()->subDays(7));

        $change24h = $at24h ? $latest->market_value - $at24h->market_value : null;
        $change3d = $at3d ? $latest->market_value - $at3d->market_value : null;
        $change7d = $at7d ? $latest->market_value - $at7d->market_value : null;

        $pctChange3d = ($at3d && $at3d->market_value > 0)
            ? round((($latest->market_value - $at3d->market_value) / $at3d->market_value) * 100, 2)
            : null;

        $pctChange7d = ($at7d && $at7d->market_value > 0)
            ? round((($latest->market_value - $at7d->market_value) / $at7d->market_value) * 100, 2)
            : null;

        // Acceleration: is today's daily move bigger or smaller than yesterday's?
        $priorDayChange = ($at24h && $at48h) ? $at24h->market_value - $at48h->market_value : null;
        $acceleration = ($change24h !== null && $priorDayChange !== null) ? $change24h - $priorDayChange : null;

        return new PlayerTrend(
            currentValue: $latest->market_value,
            change24h: $change24h,
            change3d: $change3d,
            change7d: $change7d,
            pctChange3d: $pctChange3d,
            pctChange7d: $pctChange7d,
            acceleration: $acceleration,
            classification: $this->classify($pctChange3d, $change24h, $latest->market_value),
            snapshotCount: $snapshots->count(),
        );
    }

    private function classify(?float $pctChange3d, ?int $change24h, ?int $currentValue): string
    {
        // Not enough history for a 3-day window: fall back to a 24h-based read
        // so the app still gives a rough signal from day one, at lower confidence.
        if ($pctChange3d === null) {
            if ($change24h === null || ! $currentValue) {
                return PlayerTrend::ESTABLE;
            }

            $pctChange3d = ($change24h / $currentValue) * 100;
        }

        return self::classifyPct($pctChange3d);
    }

    /**
     * Maps any percentage change onto the same MOLT_ALCISTA…MOLT_BAIXISTA
     * scale — public so other callers can classify a percentage that didn't
     * come from our own snapshots (e.g. an external source's 7-day change)
     * with the exact same thresholds the rest of the app uses, instead of
     * inventing a second scale.
     */
    public static function classifyPct(float $pct): string
    {
        return match (true) {
            $pct >= self::MOLT_ALCISTA_THRESHOLD => PlayerTrend::MOLT_ALCISTA,
            $pct >= self::ALCISTA_THRESHOLD => PlayerTrend::ALCISTA,
            $pct <= self::MOLT_BAIXISTA_THRESHOLD => PlayerTrend::MOLT_BAIXISTA,
            $pct <= self::BAIXISTA_THRESHOLD => PlayerTrend::BAIXISTA,
            default => PlayerTrend::ESTABLE,
        };
    }

    /**
     * Our own trend defaults to ESTABLE (no signal) until a snapshot from
     * ~3 real days ago exists — which used to mean every BUY/SELL-style
     * badge downstream just said "hold" for the first weeks after
     * connecting an account, regardless of what the market was actually
     * doing. When our own trend doesn't have that yet AND an (unofficial,
     * third-party) external trend is available, this falls back to
     * classifying its 7-day change on the exact same scale — never a
     * different, invented one. Used by MarketController for its
     * market-table "action" badge only; FantasyRecommendationEngine (the
     * dashboard's official recommendations) never calls this and never
     * touches FantasyExternalTrend — see FutbolFantasySyncService.
     *
     * Deliberately checks `pctChange3d !== null` rather than
     * `hasEnoughData()` (snapshotCount >= 2): a freshly-connected account
     * synced several times *today* already has 2+ snapshots, but they're
     * all from the same few hours — hasEnoughData() would say "own data is
     * fine" while classify() is still silently defaulting to ESTABLE for
     * lack of an actual multi-day-old snapshot. pctChange3d only becomes
     * non-null once a real ~3-day-old snapshot exists, which is the thing
     * that actually makes "own" trustworthy.
     *
     * @return array{classification: string, source: 'own'|'external'}
     */
    public function effectiveClassification(PlayerTrend $trend, ?FantasyExternalTrend $externalTrend): array
    {
        if ($trend->pctChange3d !== null || ! $externalTrend || $externalTrend->pct_7d === null) {
            return ['classification' => $trend->classification, 'source' => 'own'];
        }

        return ['classification' => self::classifyPct((float) $externalTrend->pct_7d), 'source' => 'external'];
    }

    /**
     * @param  Collection<int, FantasyPlayerSnapshot>  $snapshots  ordered ascending by captured_at
     */
    private function closestBefore(Collection $snapshots, Carbon $target): ?FantasyPlayerSnapshot
    {
        return $snapshots
            ->filter(fn (FantasyPlayerSnapshot $s) => $s->captured_at->lessThanOrEqualTo($target))
            ->last();
    }
}
