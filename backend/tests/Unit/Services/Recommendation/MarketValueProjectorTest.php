<?php

namespace Tests\Unit\Services\Recommendation;

use App\Services\Recommendation\MarketValueProjector;
use InvalidArgumentException;
use Tests\TestCase;

class MarketValueProjectorTest extends TestCase
{
    public function test_decay_factor_matches_the_configured_bands(): void
    {
        $projector = new MarketValueProjector([
            ['from' => 1, 'to' => 3, 'factor' => 1.00],
            ['from' => 4, 'to' => 7, 'factor' => 0.80],
            ['from' => 8, 'to' => 14, 'factor' => 0.50],
        ]);

        $this->assertSame(1.00, $projector->decayFactor(1));
        $this->assertSame(1.00, $projector->decayFactor(3));
        $this->assertSame(0.80, $projector->decayFactor(4));
        $this->assertSame(0.80, $projector->decayFactor(7));
        $this->assertSame(0.50, $projector->decayFactor(8));
        $this->assertSame(0.50, $projector->decayFactor(14));
    }

    public function test_a_day_past_the_last_band_keeps_the_last_bands_factor(): void
    {
        $projector = new MarketValueProjector([
            ['from' => 1, 'to' => 3, 'factor' => 1.00],
            ['from' => 4, 'to' => 14, 'factor' => 0.50],
        ]);

        $this->assertSame(0.50, $projector->decayFactor(30));
    }

    public function test_projects_a_flat_zero_growth_rate_as_unchanged(): void
    {
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        $this->assertEqualsWithDelta(10_000_000.0, $projector->project(10_000_000, 0.0, 14), 0.01);
    }

    public function test_compounds_a_constant_rate_across_a_single_flat_band(): void
    {
        // A single band covering the whole horizon isolates plain compound
        // interest — easy to hand-verify: 100 * 1.10^3 = 133.1.
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        $this->assertEqualsWithDelta(133.1, $projector->project(100, 0.10, 3), 0.001);
    }

    public function test_a_decayed_rate_compounds_to_less_than_the_undecayed_equivalent(): void
    {
        $decayed = new MarketValueProjector([
            ['from' => 1, 'to' => 3, 'factor' => 1.00],
            ['from' => 4, 'to' => 14, 'factor' => 0.50],
        ]);
        $flat = new MarketValueProjector([['from' => 1, 'to' => 14, 'factor' => 1.00]]);

        $this->assertLessThan($flat->project(100, 0.02, 7), $decayed->project(100, 0.02, 7));
    }

    public function test_rejects_an_empty_decay_band_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MarketValueProjector([]);
    }

    public function test_days_to_reach_is_zero_when_the_target_is_already_at_or_below_the_current_value(): void
    {
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        $this->assertSame(0, $projector->daysToReach(10_000_000, 9_500_000, 0.02, 30));
    }

    public function test_days_to_reach_is_null_when_growth_is_not_positive_and_the_target_is_above_current(): void
    {
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        $this->assertNull($projector->daysToReach(10_000_000, 10_500_000, 0.0, 30));
        $this->assertNull($projector->daysToReach(10_000_000, 10_500_000, -0.01, 30));
    }

    public function test_days_to_reach_finds_the_first_day_the_projection_clears_the_target(): void
    {
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        // 1.02^3 ≈ 1.061 > 1.05, so day 3 first clears a 5% target.
        $this->assertSame(3, $projector->daysToReach(10_000_000, 10_500_000, 0.02, 30));
    }

    public function test_days_to_reach_returns_null_when_the_target_is_not_reached_within_max_days(): void
    {
        $projector = new MarketValueProjector([['from' => 1, 'to' => 30, 'factor' => 1.00]]);

        $this->assertNull($projector->daysToReach(10_000_000, 15_000_000, 0.001, 5));
    }
}
