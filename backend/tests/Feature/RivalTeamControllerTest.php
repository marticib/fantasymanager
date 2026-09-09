<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyStanding;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RivalTeamControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, account: FantasyAccount, league: FantasyLeague, myTeam: FantasyTeam} */
    private function setupFixture(): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $myTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $myTeam->id])->save();

        return ['user' => $user, 'account' => $account->fresh(), 'league' => $league, 'myTeam' => $myTeam];
    }

    private function rivalTeam(FantasyLeague $league, string $name = 'Rival Team'): FantasyTeam
    {
        return FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => $name, 'is_mine' => false]);
    }

    private function player(string $name, string $position, int $marketValue): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => $name,
            'position' => $position,
            'market_value' => $marketValue,
            'average_points' => 5.0,
            'points' => 50,
            'status' => 'ok',
        ]);
    }

    /** 1. A rival's roster reuses the same per-player clause verdict the individual player page shows. */
    public function test_1_rival_roster_shows_the_clause_verdict_for_each_player(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $rival = $this->rivalTeam($league);
        $player = $this->player('Rival Player', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $player->id, 'clause_value' => 10_500_000]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$rival->id}");

        $response->assertOk();
        $this->assertSame('Rival Player', $response->json('players.0.name'));
        $this->assertSame('CLAUSE', $response->json('players.0.decision.type'));
        $this->assertArrayHasKey('clauseEconomicScore', $response->json('players.0.decision.raw'));
    }

    /** 2. A rival player without a known clause value never gets a fabricated decision. */
    public function test_2_no_clause_value_never_invents_a_decision(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $rival = $this->rivalTeam($league);
        $player = $this->player('No Clause Data', 'DF', 8_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $player->id, 'clause_value' => null]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$rival->id}");

        $response->assertOk();
        $this->assertNull($response->json('players.0.decision'));
        $this->assertNull($response->json('players.0.action'));
    }

    /** 3. Your own team is never reachable through the rival endpoint — /team already covers it. */
    public function test_3_own_team_is_not_reachable_through_the_rival_endpoint(): void
    {
        ['user' => $user, 'myTeam' => $myTeam] = $this->setupFixture();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$myTeam->id}");

        $response->assertNotFound();
    }

    /** 4. A team from a league you're not viewing (not your active league) is never reachable either. */
    public function test_4_team_outside_the_active_league_is_not_reachable(): void
    {
        ['user' => $user] = $this->setupFixture();
        $otherAccount = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $otherLeague = FantasyLeague::create(['fantasy_account_id' => $otherAccount->id, 'external_id' => uniqid(), 'name' => 'Someone Elses League']);
        $strangerTeam = $this->rivalTeam($otherLeague, 'Stranger');

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$strangerTeam->id}");

        $response->assertNotFound();
    }

    /** 5. isStarter is always null for rivals — the sync source never carries a starter/bench split, never guessed. */
    public function test_5_is_starter_is_always_null_for_rivals(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $rival = $this->rivalTeam($league);
        $player = $this->player('Bench Or Starter Unknown', 'FW', 12_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $player->id, 'is_starter' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$rival->id}");

        $response->assertOk();
        $this->assertNull($response->json('players.0.isStarter'));
    }

    /** 6. Team value falls back to summing real market values when fantasy_teams.team_value was never cached for this rival. */
    public function test_6_team_value_falls_back_to_summed_market_value(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $rival = $this->rivalTeam($league);
        $this->assertNull($rival->team_value);
        $p1 = $this->player('Rival One', 'DF', 5_000_000);
        $p2 = $this->player('Rival Two', 'MF', 7_500_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $p1->id]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $p2->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/standings/{$rival->id}");

        $response->assertOk();
        $this->assertSame(12_500_000, $response->json('summary.teamValue'));
        $this->assertNull($response->json('summary.cash'));
    }

    /** 7. Standings now expose each row's team id, so the frontend can link into it. */
    public function test_7_standings_expose_the_team_id(): void
    {
        ['user' => $user, 'league' => $league, 'myTeam' => $myTeam] = $this->setupFixture();
        $rival = $this->rivalTeam($league);
        FantasyStanding::create(['fantasy_league_id' => $league->id, 'fantasy_team_id' => $myTeam->id, 'position' => 1, 'points' => 30, 'captured_at' => Carbon::now()]);
        FantasyStanding::create(['fantasy_league_id' => $league->id, 'fantasy_team_id' => $rival->id, 'position' => 2, 'points' => 20, 'captured_at' => Carbon::now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/standings');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('teamId');
        $this->assertTrue($ids->contains($rival->id));
        $this->assertTrue($ids->contains($myTeam->id));
    }
}
