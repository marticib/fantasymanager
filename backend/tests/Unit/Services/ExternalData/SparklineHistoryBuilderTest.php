<?php

namespace Tests\Unit\Services\ExternalData;

use App\Models\FantasyExternalTrend;
use App\Services\ExternalData\SparklineHistoryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SparklineHistoryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function externalTrend(array $values): FantasyExternalTrend
    {
        return FantasyExternalTrend::make(array_merge([
            'value_1d' => null, 'value_3d' => null, 'value_7d' => null, 'value_14d' => null, 'value_30d' => null,
        ], $values));
    }

    public function test_prefers_our_own_history_once_it_has_enough_distinct_points(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-01T00:00:00Z'],
            ['value' => 110, 'capturedAt' => '2026-09-02T00:00:00Z'],
            ['value' => 105, 'capturedAt' => '2026-09-03T00:00:00Z'],
        ]);

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $this->externalTrend(['value_7d' => 90]), 105);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }

    public function test_falls_back_to_external_values_when_our_own_history_is_flat(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-07T18:00:00Z'],
            ['value' => 100, 'capturedAt' => '2026-09-07T19:00:00Z'],
        ]);

        $external = $this->externalTrend(['value_7d' => 90, 'value_3d' => 95, 'value_1d' => 98]);
        $now = Carbon::parse('2026-09-08T00:00:00Z');

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $external, 100, $now);

        $this->assertSame('external', $source);
        $this->assertCount(4, $points); // 7d, 3d, 1d, now
        $this->assertSame(90, $points[0]['value']);
        $this->assertSame(95, $points[1]['value']);
        $this->assertSame(98, $points[2]['value']);
        $this->assertSame(100, $points[3]['value']);
        $this->assertSame($now->toIso8601String(), $points[3]['capturedAt']);
    }

    public function test_falls_back_to_own_flat_history_when_there_is_no_external_trend_at_all(): void
    {
        $own = collect([['value' => 100, 'capturedAt' => '2026-09-07T18:00:00Z']]);

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, null, 100);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }

    public function test_falls_back_to_own_history_when_external_has_too_few_usable_points(): void
    {
        $own = collect([['value' => 100, 'capturedAt' => '2026-09-07T18:00:00Z']]);
        $external = $this->externalTrend(['value_1d' => 98]); // only 1 historical point + "now" = 2, below the minimum

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $external, 100);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }

    public function test_empty_own_history_with_no_external_returns_an_empty_own_series(): void
    {
        [$points, $source] = (new SparklineHistoryBuilder)->build(collect(), null, 100);

        $this->assertSame('own', $source);
        $this->assertSame([], $points);
    }

    public function test_supports_a_wider_30_day_window_for_the_player_detail_chart(): void
    {
        $own = collect([['value' => 100, 'capturedAt' => '2026-09-07T18:00:00Z']]);
        $external = $this->externalTrend(['value_30d' => 80, 'value_14d' => 85, 'value_7d' => 90, 'value_3d' => 95, 'value_1d' => 98]);
        $now = Carbon::parse('2026-09-08T00:00:00Z');

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $external, 100, $now, dayOffsets: [30, 14, 7, 3, 1]);

        $this->assertSame('external', $source);
        $this->assertCount(6, $points); // 30d, 14d, 7d, 3d, 1d, now
        $this->assertSame(80, $points[0]['value']);
        $this->assertSame($now->copy()->subDays(30)->toIso8601String(), $points[0]['capturedAt']);
        $this->assertSame(100, $points[5]['value']);
    }

    /**
     * The player detail page's evolution chart passes preferExternal: true —
     * "own" clearing MIN_OWN_DISTINCT_POINTS doesn't mean it's useful over a
     * wide window: a freshly-connected account can have 3 distinct points
     * that all sit in the last day or two, while futbolfantasy already has
     * real history spanning the whole 30-day window.
     */
    public function test_prefer_external_uses_external_even_when_own_already_has_enough_distinct_points(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-07T10:00:00Z'],
            ['value' => 101, 'capturedAt' => '2026-09-07T18:00:00Z'],
            ['value' => 102, 'capturedAt' => '2026-09-08T00:00:00Z'],
        ]);
        $external = $this->externalTrend(['value_30d' => 80, 'value_14d' => 85, 'value_7d' => 90, 'value_3d' => 95, 'value_1d' => 98]);
        $now = Carbon::parse('2026-09-08T00:00:00Z');

        [$points, $source] = (new SparklineHistoryBuilder)->build(
            $own, $external, 102, $now, dayOffsets: [30, 14, 7, 3, 1], preferExternal: true,
        );

        $this->assertSame('external', $source);
        $this->assertCount(6, $points);
        $this->assertSame(80, $points[0]['value']);
    }

    public function test_prefer_external_still_falls_back_to_own_when_there_is_no_external_trend(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-07T10:00:00Z'],
            ['value' => 101, 'capturedAt' => '2026-09-07T18:00:00Z'],
            ['value' => 102, 'capturedAt' => '2026-09-08T00:00:00Z'],
        ]);

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, null, 102, preferExternal: true);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }

    public function test_prefer_external_still_falls_back_to_own_when_external_has_too_few_usable_points(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-07T10:00:00Z'],
            ['value' => 101, 'capturedAt' => '2026-09-07T18:00:00Z'],
            ['value' => 102, 'capturedAt' => '2026-09-08T00:00:00Z'],
        ]);
        $external = $this->externalTrend(['value_1d' => 98]); // 1 historical point + "now" = 2, below the minimum

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $external, 102, preferExternal: true);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }

    public function test_default_behaviour_is_unchanged_for_other_screens_market_team_clauses(): void
    {
        $own = collect([
            ['value' => 100, 'capturedAt' => '2026-09-06T00:00:00Z'],
            ['value' => 101, 'capturedAt' => '2026-09-07T00:00:00Z'],
            ['value' => 102, 'capturedAt' => '2026-09-08T00:00:00Z'],
        ]);
        $external = $this->externalTrend(['value_7d' => 90]);

        [$points, $source] = (new SparklineHistoryBuilder)->build($own, $external, 102);

        $this->assertSame('own', $source);
        $this->assertSame($own->values()->all(), $points);
    }
}
