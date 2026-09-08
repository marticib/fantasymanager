<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyPlayer;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FantasyScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FantasyScoreService
    {
        return new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService);
    }

    public function test_a_strong_cheap_starter_scores_highly(): void
    {
        $player = FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => 'Star Player',
            'position' => 'MF',
            'average_points' => 7.5,
            'points' => 90,
            'market_value' => 4_000_000,
            'status' => 'ok',
        ]);

        $result = $this->service()->compute($player);

        $this->assertGreaterThanOrEqual(70, $result->total);
        $this->assertEquals(100, $result->breakdown['risk']);
    }

    public function test_an_injured_low_performer_scores_poorly(): void
    {
        $player = FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => 'Bench Warmer',
            'position' => 'DF',
            'average_points' => 0.5,
            'points' => 2,
            'market_value' => 9_000_000,
            'status' => 'injured',
        ]);

        $result = $this->service()->compute($player);

        $this->assertLessThan(35, $result->total);
        $this->assertEquals(0, $result->breakdown['risk']);
    }

    public function test_weights_always_sum_to_one_hundred_even_with_custom_overrides(): void
    {
        $settings = new FantasySettingsService;
        $settings->set('performance', 40);
        $settings->set('risk', 10);

        $weights = $settings->scoreWeights();

        $this->assertEqualsWithDelta(100.0, array_sum($weights), 0.01);
    }

    public function test_confidence_is_low_with_no_snapshot_history(): void
    {
        $player = FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => 'Unknown Quantity',
            'position' => 'FW',
        ]);

        $result = $this->service()->compute($player);

        $this->assertLessThan(50, $result->confidence);
    }
}
