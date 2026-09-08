<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Models\User;
use App\Services\ExternalData\SparklineHistoryBuilder;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerTrendPresenterTest extends TestCase
{
    use RefreshDatabase;

    private function presenter(): PlayerTrendPresenter
    {
        return new PlayerTrendPresenter(new TrendAnalysisService, new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService), new SparklineHistoryBuilder);
    }

    public function test_falls_back_to_external_classification_and_reports_the_source(): void
    {
        $player = FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => 'Freshly Connected',
            'market_value' => 5_000_000,
            'average_points' => 3,
        ]);

        $external = FantasyExternalTrend::create([
            'fantasy_player_id' => $player->id,
            'source' => 'futbolfantasy',
            'match_confidence' => 'exact',
            'pct_7d' => -15,
            'value_7d' => 5_800_000,
            'value_3d' => 5_300_000,
            'value_1d' => 5_050_000,
            'fetched_at' => now(),
        ]);

        $result = $this->presenter()->present($player, FantasyAccount::create(['user_id' => User::factory()->create()->id]), $external);

        $this->assertSame('external', $result->effectiveSource);
        $this->assertTrue($result->isFalling());
        $this->assertNotEmpty($result->history);
        $this->assertSame('external', $result->historySource);
        $this->assertSame(-15.0, $result->externalTrendPayload()['pct7d']);
    }

    public function test_prefers_own_trend_and_omits_external_payload_when_there_is_none(): void
    {
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'No External Match', 'market_value' => 1_000_000]);
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);

        $result = $this->presenter()->present($player, $account, null);

        $this->assertSame('own', $result->effectiveSource);
        $this->assertNull($result->externalTrendPayload());
        $this->assertFalse($result->isRising());
        $this->assertFalse($result->isFalling());
    }
}
