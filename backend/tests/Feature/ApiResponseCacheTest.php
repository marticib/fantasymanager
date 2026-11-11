<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyStanding;
use App\Models\FantasyTeam;
use App\Models\User;
use App\Support\DataVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, account: FantasyAccount, league: FantasyLeague, team: FantasyTeam} */
    private function fixture(): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Mine', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return compact('user', 'account', 'league', 'team');
    }

    private function standing(FantasyLeague $league, FantasyTeam $team, int $points, string $capturedAt): void
    {
        FantasyStanding::create([
            'fantasy_league_id' => $league->id,
            'fantasy_team_id' => $team->id,
            'position' => 1,
            'points' => $points,
            'captured_at' => $capturedAt,
        ]);
    }

    public function test_a_repeated_read_is_served_from_cache_until_the_data_version_changes(): void
    {
        ['user' => $user, 'account' => $account, 'league' => $league, 'team' => $team] = $this->fixture();
        $this->standing($league, $team, 10, '2026-01-01 10:00:00');

        $first = $this->actingAs($user, 'sanctum')->getJson('/api/standings');
        $first->assertOk()->assertHeader('X-Api-Cache', 'miss');
        $this->assertSame(10, $first->json('data.0.points'));

        // New data written straight to the DB (no sync, no action) — still the cached copy.
        $this->standing($league, $team, 99, '2026-01-02 10:00:00');
        $second = $this->actingAs($user, 'sanctum')->getJson('/api/standings');
        $second->assertHeader('X-Api-Cache', 'hit');
        $this->assertSame(10, $second->json('data.0.points'));

        // What a finished sync does.
        DataVersion::bumpAccount($account->id);
        $third = $this->actingAs($user, 'sanctum')->getJson('/api/standings');
        $third->assertHeader('X-Api-Cache', 'miss');
        $this->assertSame(99, $third->json('data.0.points'));
    }

    public function test_any_successful_write_invalidates_the_accounts_cached_reads(): void
    {
        ['user' => $user, 'league' => $league, 'team' => $team] = $this->fixture();
        $this->standing($league, $team, 10, '2026-01-01 10:00:00');

        $this->actingAs($user, 'sanctum')->getJson('/api/standings')->assertHeader('X-Api-Cache', 'miss');
        $this->standing($league, $team, 99, '2026-01-02 10:00:00');

        $this->actingAs($user, 'sanctum')->putJson('/api/settings', [])->assertOk();

        $after = $this->actingAs($user, 'sanctum')->getJson('/api/standings');
        $after->assertHeader('X-Api-Cache', 'miss');
        $this->assertSame(99, $after->json('data.0.points'));
    }

    public function test_cached_responses_are_never_shared_between_accounts(): void
    {
        ['user' => $userA, 'league' => $leagueA, 'team' => $teamA] = $this->fixture();
        ['user' => $userB, 'league' => $leagueB, 'team' => $teamB] = $this->fixture();
        $this->standing($leagueA, $teamA, 10, '2026-01-01 10:00:00');
        $this->standing($leagueB, $teamB, 20, '2026-01-01 10:00:00');

        $this->assertSame(10, $this->actingAs($userA, 'sanctum')->getJson('/api/standings')->json('data.0.points'));

        $b = $this->actingAs($userB, 'sanctum')->getJson('/api/standings');
        $b->assertHeader('X-Api-Cache', 'miss');
        $this->assertSame(20, $b->json('data.0.points'));
    }
}
