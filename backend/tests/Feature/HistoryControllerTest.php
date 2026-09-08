<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HistoryControllerTest extends TestCase
{
    use RefreshDatabase;

    private function setUpAccountWithTeam(): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T1', 'name' => 'My Team', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return [$user, $account->fresh(), $team];
    }

    public function test_team_value_history_is_bounded_to_the_last_30_days(): void
    {
        [$user, , $team] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Old And New']);

        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $player->id,
            'owner_team_id' => $team->id,
            'market_value' => 5_000_000,
            'captured_at' => Carbon::now()->subDays(45), // outside the 30-day window
        ]);
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $player->id,
            'owner_team_id' => $team->id,
            'market_value' => 6_000_000,
            'captured_at' => Carbon::now()->subDays(10), // inside the window
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $days = collect($response->json('data'))->pluck('day');
        $this->assertCount(1, $days);
        $this->assertSame(Carbon::now()->subDays(10)->toDateString(), $days->first());
    }

    public function test_sums_market_value_across_the_whole_roster_per_day(): void
    {
        [$user, , $team] = $this->setUpAccountWithTeam();
        $playerA = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Player A']);
        $playerB = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Player B']);
        $capturedAt = Carbon::now()->subDays(2);

        foreach ([$playerA, $playerB] as $player) {
            FantasyPlayerSnapshot::create([
                'fantasy_player_id' => $player->id,
                'owner_team_id' => $team->id,
                'market_value' => 4_000_000,
                'captured_at' => $capturedAt,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('day', $capturedAt->toDateString());
        $this->assertSame(8_000_000, (int) $row['team_value']);
        $this->assertSame(2, (int) $row['player_count']);
    }

    public function test_each_day_includes_a_per_player_breakdown_sorted_by_value_descending(): void
    {
        [$user, , $team] = $this->setUpAccountWithTeam();
        $goalkeeper = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Keeper', 'position' => 'GK']);
        $striker = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Striker', 'position' => 'FW']);
        $capturedAt = Carbon::now()->subDays(1);

        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $goalkeeper->id,
            'owner_team_id' => $team->id,
            'market_value' => 3_000_000,
            'captured_at' => $capturedAt,
        ]);
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $striker->id,
            'owner_team_id' => $team->id,
            'market_value' => 9_000_000,
            'captured_at' => $capturedAt,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('day', $capturedAt->toDateString());
        $this->assertCount(2, $row['players']);
        // Highest value first.
        $this->assertSame('Striker', $row['players'][0]['name']);
        $this->assertSame(9_000_000, $row['players'][0]['marketValue']);
        $this->assertSame('FW', $row['players'][0]['position']);
        $this->assertSame('Keeper', $row['players'][1]['name']);
        $this->assertSame(3_000_000, $row['players'][1]['marketValue']);
    }

    public function test_each_player_and_the_team_total_report_the_change_vs_the_previous_day(): void
    {
        [$user, , $team] = $this->setUpAccountWithTeam();
        $veteran = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Veteran']);
        $newSigning = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'New Signing']);

        // Day 1: only the veteran, at 10M.
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $veteran->id,
            'owner_team_id' => $team->id,
            'market_value' => 10_000_000,
            'captured_at' => Carbon::now()->subDays(2),
        ]);
        // Day 2: veteran rises to 11M (+10%), new signing joins at 5M (no prior day to compare).
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $veteran->id,
            'owner_team_id' => $team->id,
            'market_value' => 11_000_000,
            'captured_at' => Carbon::now()->subDays(1),
        ]);
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $newSigning->id,
            'owner_team_id' => $team->id,
            'market_value' => 5_000_000,
            'captured_at' => Carbon::now()->subDays(1),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');
        $response->assertOk();
        $rows = collect($response->json('data'));

        $day1 = $rows->firstWhere('day', Carbon::now()->subDays(2)->toDateString());
        $this->assertNull($day1['team_value_change']); // nothing before it in the window
        $this->assertNull($day1['players'][0]['change']);

        $day2 = $rows->firstWhere('day', Carbon::now()->subDays(1)->toDateString());
        $this->assertSame(6_000_000, $day2['team_value_change']); // (11M + 5M) - 10M
        $veteranRow = collect($day2['players'])->firstWhere('name', 'Veteran');
        $newSigningRow = collect($day2['players'])->firstWhere('name', 'New Signing');
        $this->assertSame(1_000_000, $veteranRow['change']);
        $this->assertEqualsWithDelta(10.0, $veteranRow['changePct'], 0.01);
        $this->assertNull($newSigningRow['change']); // just joined, nothing to compare against
    }

    public function test_uses_only_the_latest_snapshot_per_player_per_day_not_the_sum_of_every_sync(): void
    {
        // A day with several syncs (fantasy:sync-team can run every 30 min)
        // must count each player once, at their last known value that day —
        // not add up every sync's snapshot, which used to inflate the total
        // to many times the real team value.
        [$user, , $team] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Synced Often']);
        $day = Carbon::now()->subDays(1)->startOfDay()->addHours(10);

        foreach ([1_000_000, 1_050_000, 1_100_000, 1_080_000] as $i => $value) {
            FantasyPlayerSnapshot::create([
                'fantasy_player_id' => $player->id,
                'owner_team_id' => $team->id,
                'market_value' => $value,
                'captured_at' => $day->copy()->addHours($i),
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('day', $day->toDateString());
        $this->assertSame(1_080_000, (int) $row['team_value']);
        $this->assertSame(1, (int) $row['player_count']);
    }

    public function test_returns_an_empty_message_when_there_is_no_active_team(): void
    {
        $user = User::factory()->create();
        FantasyAccount::create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertNotEmpty($response->json('message'));
    }
}
