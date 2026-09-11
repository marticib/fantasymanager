<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, account: FantasyAccount, team: FantasyTeam} */
    private function setupFixture(): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'team_value' => 10_500_000]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return ['user' => $user, 'account' => $account->fresh(), 'team' => $team];
    }

    /** 24h squad-value delta reaches the /team response, reusing TeamValueDeltaService (already unit-tested for its math). */
    public function test_team_summary_includes_the_24h_squad_value_delta(): void
    {
        ['user' => $user, 'team' => $team] = $this->setupFixture();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Player One', 'market_value' => 10_500_000, 'average_points' => 5.0, 'status' => 'ok']);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        FantasyPlayerSnapshot::create(['fantasy_player_id' => $player->id, 'owner_team_id' => $team->id, 'market_value' => 10_000_000, 'captured_at' => now()->subDay()]);
        FantasyPlayerSnapshot::create(['fantasy_player_id' => $player->id, 'owner_team_id' => $team->id, 'market_value' => 10_500_000, 'captured_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/team');

        $response->assertOk();
        $this->assertSame(500_000, $response->json('summary.teamValueDelta24h'));
        // assertEquals: a whole-number percentage round-trips through JSON as an int, not a float.
        $this->assertEquals(5.0, $response->json('summary.teamValueDelta24hPct'));
    }

    /** No snapshot reaching back 24h never invents a delta — null, not zero. */
    public function test_team_summary_delta_is_null_without_a_24h_old_snapshot(): void
    {
        ['user' => $user, 'team' => $team] = $this->setupFixture();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Freshly Synced', 'market_value' => 5_000_000, 'average_points' => 5.0, 'status' => 'ok']);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);
        FantasyPlayerSnapshot::create(['fantasy_player_id' => $player->id, 'owner_team_id' => $team->id, 'market_value' => 5_000_000, 'captured_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/team');

        $response->assertOk();
        $this->assertNull($response->json('summary.teamValueDelta24h'));
        $this->assertNull($response->json('summary.teamValueDelta24hPct'));
    }
}
