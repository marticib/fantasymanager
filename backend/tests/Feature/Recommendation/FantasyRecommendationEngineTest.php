<?php

namespace Tests\Feature\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyRecommendation;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use App\Services\Recommendation\FantasyRecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FantasyRecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setUpAccountWithTeam(int $money): array
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Test League']);
        $team = FantasyTeam::create([
            'fantasy_league_id' => $league->id,
            'external_id' => 'T1',
            'name' => 'My Team',
            'is_mine' => true,
            'money' => $money,
        ]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return [$account->fresh(), $league, $team];
    }

    private function playerWithSnapshots(array $attributes, array $valuesByDaysAgo): FantasyPlayer
    {
        $player = FantasyPlayer::create(array_merge(['external_id' => uniqid()], $attributes));

        foreach ($valuesByDaysAgo as $daysAgo => $value) {
            $player->snapshots()->create(['market_value' => $value, 'captured_at' => now()->subDays($daysAgo)]);
        }

        return $player;
    }

    public function test_a_low_performer_losing_value_fast_is_recommended_to_sell(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        [$account, , $team] = $this->setUpAccountWithTeam(money: 10_000_000);

        $player = $this->playerWithSnapshots(
            ['name' => 'Fading Star', 'position' => 'MF', 'average_points' => 2.5, 'points' => 20, 'status' => 'ok'],
            [3 => 9_200_000, 2 => 9_050_000, 1 => 8_950_000, 0 => 8_800_000],
        );
        // Sync the player's own current market_value/points to match the latest snapshot.
        $player->update(['market_value' => 8_800_000, 'points' => 20, 'average_points' => 2.5]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $recommendations = $this->app->make(FantasyRecommendationEngine::class)->generate($account);

        $rec = $recommendations->firstWhere('fantasy_player_id', $player->id);
        $this->assertNotNull($rec);
        $this->assertSame(FantasyRecommendation::ACTION_SELL, $rec->action);
        $this->assertContains($rec->priority, [FantasyRecommendation::PRIORITY_CRITICAL, FantasyRecommendation::PRIORITY_HIGH]);
    }

    public function test_a_high_performer_losing_value_is_held_not_sold(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        [$account, , $team] = $this->setUpAccountWithTeam(money: 10_000_000);

        $player = $this->playerWithSnapshots(
            ['name' => 'Elite Playmaker', 'position' => 'MF', 'average_points' => 8.5, 'points' => 95, 'status' => 'ok'],
            [3 => 8_600_000, 2 => 8_520_000, 1 => 8_460_000, 0 => 8_400_000],
        );
        $player->update(['market_value' => 8_400_000, 'points' => 95, 'average_points' => 8.5]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $recommendations = $this->app->make(FantasyRecommendationEngine::class)->generate($account);

        $rec = $recommendations->firstWhere('fantasy_player_id', $player->id);
        $this->assertNotNull($rec);
        $this->assertSame(FantasyRecommendation::ACTION_HOLD, $rec->action);
    }

    public function test_a_rising_undervalued_market_player_is_recommended_to_buy_within_budget(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        // 5M cash, 3M default minimum reserve => 2M available capital.
        [$account, $league] = $this->setUpAccountWithTeam(money: 5_000_000);

        $player = $this->playerWithSnapshots(
            ['name' => 'Rising Talent', 'position' => 'FW', 'average_points' => 7.2, 'points' => 80, 'status' => 'ok'],
            [3 => 2_700_000, 2 => 2_820_000, 1 => 2_920_000, 0 => 3_000_000],
        );
        $player->update(['market_value' => 3_000_000, 'points' => 80, 'average_points' => 7.2]);

        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => 3_000_000,
            'is_on_market' => true,
        ]);

        $recommendations = $this->app->make(FantasyRecommendationEngine::class)->generate($account);

        $rec = $recommendations->firstWhere('fantasy_player_id', $player->id);
        $this->assertNotNull($rec);
        $this->assertSame(FantasyRecommendation::ACTION_BUY, $rec->action);

        // Maximum bid must never exceed available capital (cash - minimum reserve),
        // and the recommended bid must never exceed the maximum.
        $this->assertLessThanOrEqual(2_000_000, $rec->max_amount);
        $this->assertLessThanOrEqual($rec->max_amount, $rec->recommended_amount);
    }

    public function test_generating_recommendations_expires_the_previous_batch(): void
    {
        Carbon::setTestNow('2026-08-13 12:00:00');
        [$account, , $team] = $this->setUpAccountWithTeam(money: 10_000_000);

        $player = $this->playerWithSnapshots(
            ['name' => 'Steady Eddie', 'position' => 'DF', 'average_points' => 5, 'points' => 50],
            [3 => 5_000_000, 2 => 5_010_000, 1 => 5_005_000, 0 => 5_020_000],
        );
        $player->update(['market_value' => 5_020_000]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $engine = $this->app->make(FantasyRecommendationEngine::class);
        $first = $engine->generate($account);
        $engine->generate($account);

        $this->assertSame(
            FantasyRecommendation::STATUS_EXPIRED,
            FantasyRecommendation::find($first->first()->id)->status,
        );
        $this->assertSame(
            1,
            FantasyRecommendation::where('fantasy_account_id', $account->id)
                ->where('status', FantasyRecommendation::STATUS_ACTIVE)
                ->where('fantasy_player_id', $player->id)
                ->count(),
        );
    }
}
