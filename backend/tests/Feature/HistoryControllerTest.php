<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyDailyReport;
use App\Models\FantasyDecisionSnapshot;
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

    /** 9. playersValue 90M + cash 10M -> total 100M, reusing fantasy_daily_reports, never a guess. */
    public function test_total_value_combines_players_value_with_the_real_recorded_cash_balance(): void
    {
        [$user, $account, $team] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Solo Player']);
        $day = Carbon::now()->subDays(1);

        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $player->id, 'owner_team_id' => $team->id,
            'market_value' => 90_000_000, 'captured_at' => $day,
        ]);
        FantasyDailyReport::create([
            'fantasy_account_id' => $account->id, 'report_date' => $day->toDateString(),
            'summary' => ['cash' => 10_000_000, 'teamValue' => 90_000_000],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('day', $day->toDateString());
        $this->assertSame(90_000_000, $row['players_value']);
        $this->assertSame(10_000_000, $row['cash_balance']);
        $this->assertSame(100_000_000, $row['total_value']);
    }

    /** A day with no matching daily report has a null cash/total — never guessed. */
    public function test_a_day_without_a_daily_report_has_no_cash_or_total_value(): void
    {
        [$user, , $team] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Solo Player']);
        $day = Carbon::now()->subDays(1);

        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $player->id, 'owner_team_id' => $team->id,
            'market_value' => 50_000_000, 'captured_at' => $day,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('day', $day->toDateString());
        $this->assertNull($row['cash_balance']);
        $this->assertNull($row['total_value']);
    }

    /** 10. initial 100M, current 120M -> growth +20M, ROI +20%. */
    public function test_summary_computes_growth_and_roi_from_first_and_last_total_value(): void
    {
        [$user, $account, $team] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Growing Player']);

        $first = Carbon::now()->subDays(3);
        $last = Carbon::now()->subDays(1);

        FantasyPlayerSnapshot::create(['fantasy_player_id' => $player->id, 'owner_team_id' => $team->id, 'market_value' => 90_000_000, 'captured_at' => $first]);
        FantasyDailyReport::create(['fantasy_account_id' => $account->id, 'report_date' => $first->toDateString(), 'summary' => ['cash' => 10_000_000]]);

        FantasyPlayerSnapshot::create(['fantasy_player_id' => $player->id, 'owner_team_id' => $team->id, 'market_value' => 108_000_000, 'captured_at' => $last]);
        FantasyDailyReport::create(['fantasy_account_id' => $account->id, 'report_date' => $last->toDateString(), 'summary' => ['cash' => 12_000_000]]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertSame(100_000_000, $summary['initialValue']);
        $this->assertSame(120_000_000, $summary['currentValue']);
        $this->assertSame(20_000_000, $summary['growth']);
        $this->assertEqualsWithDelta(20.0, $summary['roiPct'], 0.01);
        $this->assertTrue($summary['includesCash']);
    }

    /** 14. No snapshots at all -> a correct empty state, not a fabricated summary. */
    public function test_summary_is_null_when_there_is_no_history_at_all(): void
    {
        [$user] = $this->setUpAccountWithTeam();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/team-value');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertNull($response->json('summary'));
    }

    /** 15. No evaluated decisions yet -> null metrics, never a fake 0%. */
    public function test_assistant_performance_does_not_show_zero_percent_when_nothing_is_evaluated_yet(): void
    {
        [$user, $account] = $this->setUpAccountWithTeam();
        FantasyDecisionSnapshot::create([
            'fantasy_account_id' => $account->id, 'fantasy_player_id' => FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'P'])->id,
            'decision_type' => 'MARKET_BUY', 'action' => 'BUY', 'horizon_days' => 14,
            'snapshot_date' => Carbon::now()->toDateString(), 'payload' => [], 'algorithm_version' => 'v1',
            'status' => FantasyDecisionSnapshot::STATUS_PENDING, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/assistant-performance');

        $response->assertOk();
        $this->assertSame(0, $response->json('evaluatedCount'));
        $this->assertSame(1, $response->json('pendingCount'));
        $this->assertNull($response->json('accuracyPct'));
        $this->assertNull($response->json('theoreticalProfit'));
    }

    /** 11. Pending decisions never count toward accuracy's denominator. */
    public function test_accuracy_excludes_pending_decisions(): void
    {
        [$user, $account] = $this->setUpAccountWithTeam();
        $playerA = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'A']);
        $playerB = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'B']);

        FantasyDecisionSnapshot::create([
            'fantasy_account_id' => $account->id, 'fantasy_player_id' => $playerA->id,
            'decision_type' => 'MARKET_BUY', 'action' => 'BUY', 'horizon_days' => 14,
            'snapshot_date' => Carbon::now()->subDays(20)->toDateString(), 'payload' => [], 'algorithm_version' => 'v1',
            'status' => FantasyDecisionSnapshot::STATUS_EVALUATED, 'outcome' => ['favorable' => true, 'realProfit' => 1000],
            'generated_at' => now(), 'evaluated_at' => now(),
        ]);
        FantasyDecisionSnapshot::create([
            'fantasy_account_id' => $account->id, 'fantasy_player_id' => $playerB->id,
            'decision_type' => 'MARKET_BUY', 'action' => 'BUY', 'horizon_days' => 14,
            'snapshot_date' => Carbon::now()->toDateString(), 'payload' => [], 'algorithm_version' => 'v1',
            'status' => FantasyDecisionSnapshot::STATUS_PENDING, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/assistant-performance');

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('accuracyPct'));
        $this->assertSame(1, $response->json('evaluatedCount'));
        $this->assertSame(1, $response->json('pendingCount'));
    }

    public function test_decisions_list_can_be_filtered_by_action_and_status(): void
    {
        [$user, $account] = $this->setUpAccountWithTeam();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Filter Target']);
        $other = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Other']);

        FantasyDecisionSnapshot::create([
            'fantasy_account_id' => $account->id, 'fantasy_player_id' => $player->id,
            'decision_type' => 'MARKET_BUY', 'action' => 'BUY', 'horizon_days' => 14,
            'snapshot_date' => Carbon::now()->toDateString(), 'payload' => [], 'algorithm_version' => 'v1',
            'status' => FantasyDecisionSnapshot::STATUS_PENDING, 'generated_at' => now(),
        ]);
        FantasyDecisionSnapshot::create([
            'fantasy_account_id' => $account->id, 'fantasy_player_id' => $other->id,
            'decision_type' => 'ROSTER', 'action' => 'HOLD', 'horizon_days' => 7,
            'snapshot_date' => Carbon::now()->toDateString(), 'payload' => [], 'algorithm_version' => 'v1',
            'status' => FantasyDecisionSnapshot::STATUS_PENDING, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/history/decisions?action=BUY');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Filter Target', $response->json('data.0.player.name'));
    }
}
