<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Services\Recommendation\TrendAnalysisService;
use App\Services\Recommendation\ValueObjects\PlayerTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrendAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function playerWithSnapshots(array $valuesByDaysAgo): FantasyPlayer
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Test Player']);

        foreach ($valuesByDaysAgo as $daysAgo => $value) {
            $player->snapshots()->create([
                'market_value' => $value,
                'captured_at' => now()->subDays($daysAgo),
            ]);
        }

        return $player;
    }

    public function test_classifies_a_strong_rising_trend_as_molt_alcista(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');

        // From 500k to 550k over 3 days is +10%, above the +8% MOLT_ALCISTA threshold.
        $player = $this->playerWithSnapshots([3 => 500_000, 2 => 520_000, 1 => 535_000, 0 => 550_000]);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertSame(PlayerTrend::MOLT_ALCISTA, $trend->classification);
        $this->assertSame(15_000, $trend->change24h);
        $this->assertSame(50_000, $trend->change3d);
        $this->assertTrue($trend->isRising());
        $this->assertTrue($trend->hasEnoughData());
    }

    public function test_classifies_a_falling_trend_as_baixista_or_worse(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');

        $player = $this->playerWithSnapshots([3 => 5_000_000, 2 => 4_900_000, 1 => 4_820_000, 0 => 4_750_000]);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertTrue($trend->isFalling());
        $this->assertSame(-250_000, $trend->change3d);
    }

    public function test_stable_trend_within_thresholds_is_estable(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');

        $player = $this->playerWithSnapshots([3 => 5_000_000, 2 => 5_010_000, 1 => 5_005_000, 0 => 5_020_000]);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertSame(PlayerTrend::ESTABLE, $trend->classification);
        $this->assertFalse($trend->isRising());
        $this->assertFalse($trend->isFalling());
    }

    /** pctChange24h — the % counterpart of the existing money-delta change24h, same shape as pctChange3d/pctChange7d. */
    public function test_pct_change_24h_is_computed_the_same_way_as_pct_change_3d_and_7d(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');

        // 500k -> 550k over the last 24h = +10%.
        $player = $this->playerWithSnapshots([3 => 400_000, 1 => 500_000, 0 => 550_000]);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertSame(10.0, $trend->pctChange24h);
    }

    public function test_pct_change_24h_is_null_without_a_24h_old_snapshot(): void
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Just Connected']);
        $player->snapshots()->create(['market_value' => 500_000, 'captured_at' => now()]);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertNull($trend->pctChange24h);
    }

    public function test_no_snapshots_returns_low_confidence_estable_trend(): void
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'No Data']);

        $trend = (new TrendAnalysisService)->analyze($player);

        $this->assertSame(PlayerTrend::ESTABLE, $trend->classification);
        $this->assertFalse($trend->hasEnoughData());
        $this->assertSame(0, $trend->snapshotCount);
    }

    public function test_effective_classification_prefers_our_own_trend_when_it_has_enough_data(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        $player = $this->playerWithSnapshots([3 => 500_000, 2 => 520_000, 1 => 535_000, 0 => 550_000]);
        $trend = (new TrendAnalysisService)->analyze($player);

        $external = FantasyExternalTrend::make(['pct_7d' => -20]); // would say MOLT_BAIXISTA if used

        $result = (new TrendAnalysisService)->effectiveClassification($trend, $external);

        $this->assertSame(PlayerTrend::MOLT_ALCISTA, $result['classification']);
        $this->assertSame('own', $result['source']);
    }

    public function test_effective_classification_falls_back_to_external_even_with_several_same_day_snapshots(): void
    {
        // The real bug this guards against: a freshly-connected account synced
        // several times today already has snapshotCount >= 2 (so
        // hasEnoughData() alone would say "own is fine"), but every snapshot
        // is from the same few hours — no real ~3-day-old point exists yet,
        // so pctChange3d is still null and classification is still ESTABLE.
        Carbon::setTestNow('2026-08-13 18:00:00');
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Synced Several Times Today']);
        foreach ([7, 5, 2, 0] as $hoursAgo) {
            $player->snapshots()->create(['market_value' => 500_000 + $hoursAgo * 100, 'captured_at' => now()->subHours($hoursAgo)]);
        }
        $trend = (new TrendAnalysisService)->analyze($player);
        $this->assertTrue($trend->snapshotCount >= 2);
        $this->assertNull($trend->pctChange3d);
        $this->assertSame(PlayerTrend::ESTABLE, $trend->classification);

        $external = FantasyExternalTrend::make(['pct_7d' => -15]);

        $result = (new TrendAnalysisService)->effectiveClassification($trend, $external);

        $this->assertSame(PlayerTrend::MOLT_BAIXISTA, $result['classification']);
        $this->assertSame('external', $result['source']);
    }

    public function test_effective_classification_falls_back_to_external_pct7d_when_our_own_trend_has_no_data(): void
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Freshly Connected']);
        $trend = (new TrendAnalysisService)->analyze($player); // ESTABLE, no data

        $external = FantasyExternalTrend::make(['pct_7d' => -12.5]);

        $result = (new TrendAnalysisService)->effectiveClassification($trend, $external);

        $this->assertSame(PlayerTrend::MOLT_BAIXISTA, $result['classification']);
        $this->assertSame('external', $result['source']);
    }

    public function test_effective_classification_stays_estable_with_no_data_at_all(): void
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'No Data Anywhere']);
        $trend = (new TrendAnalysisService)->analyze($player);

        $result = (new TrendAnalysisService)->effectiveClassification($trend, null);

        $this->assertSame(PlayerTrend::ESTABLE, $result['classification']);
        $this->assertSame('own', $result['source']);
    }
}
