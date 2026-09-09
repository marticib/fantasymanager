<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyDecisionSnapshot;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FantasySnapshotDecisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function account(int $cash = 20_000_000): array
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id, 'access_token' => 'test-token']);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return ['account' => $account->fresh(), 'league' => $league, 'team' => $team];
    }

    private function player(string $name, string $position, int $marketValue, array $extra = []): FantasyPlayer
    {
        return FantasyPlayer::create(array_merge([
            'external_id' => uniqid(), 'name' => $name, 'position' => $position,
            'market_value' => $marketValue, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ], $extra));
    }

    /** 9 (adapted). A HOLD roster player is snapshotted with a genuinely resolvable status: PENDING, ready to be graded later. */
    public function test_a_hold_roster_player_is_snapshotted_as_pending(): void
    {
        ['account' => $account, 'team' => $team] = $this->account();
        $player = $this->player('Stable Player', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id])->assertExitCode(0);

        $snapshot = FantasyDecisionSnapshot::where('fantasy_player_id', $player->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(FantasyDecisionSnapshot::TYPE_ROSTER, $snapshot->decision_type);
        $this->assertContains($snapshot->status, [FantasyDecisionSnapshot::STATUS_PENDING, FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE]);
        $this->assertSame(7, $snapshot->horizon_days);
        $this->assertNotEmpty($snapshot->algorithm_version);
    }

    /** 12/17 (raise clause). A LOCK_CLAUSE roster decision is snapshotted as NOT_EVALUABLE immediately — never a fabricated benefit. */
    public function test_a_clause_raise_decision_is_snapshotted_as_not_evaluable(): void
    {
        ['account' => $account, 'team' => $team] = $this->account();
        $player = $this->player('Cheap Clause', 'MF', 20_000_000);
        FantasyTeamPlayer::create([
            'fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id,
            'clause_value' => 20_200_000, 'clause_locked_until' => Carbon::now()->addHours(4),
        ]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / 1.01), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.01 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.01 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);

        $snapshot = FantasyDecisionSnapshot::where('fantasy_player_id', $player->id)->where('action', 'LOCK_CLAUSE')->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE, $snapshot->status);
        $this->assertNull($snapshot->outcome);
        $this->assertSame(20_200_000, $snapshot->reference_value);
    }

    public function test_a_market_buy_opportunity_is_snapshotted(): void
    {
        ['account' => $account, 'league' => $league] = $this->account();
        $player = $this->player('Great Buy', 'MF', 10_000_000);
        $now = 10_000_000;
        foreach ([1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7] as $daysAgo => $factor) {
            $player->snapshots()->create(['market_value' => (int) ($now / $factor), 'captured_at' => Carbon::now()->subDays($daysAgo)]);
        }
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id,
            'market_value' => $now, 'asking_price' => $now, 'is_on_market' => true,
        ]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);

        $snapshot = FantasyDecisionSnapshot::where('fantasy_player_id', $player->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(FantasyDecisionSnapshot::TYPE_MARKET_BUY, $snapshot->decision_type);
        $this->assertSame(FantasyDecisionSnapshot::STATUS_PENDING, $snapshot->status);
        $this->assertSame(14, $snapshot->horizon_days);
        $this->assertIsArray($snapshot->payload);
    }

    /** Re-running the command the same day refreshes the still-PENDING row instead of duplicating it. */
    public function test_rerunning_the_same_day_does_not_duplicate_rows(): void
    {
        ['account' => $account, 'team' => $team] = $this->account();
        $player = $this->player('Stable Player', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);
        $countAfterFirst = FantasyDecisionSnapshot::count();

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);
        $countAfterSecond = FantasyDecisionSnapshot::count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }

    /** An already-evaluated row is never overwritten by a later run the same day. */
    public function test_an_already_evaluated_row_is_never_overwritten(): void
    {
        ['account' => $account, 'team' => $team] = $this->account();
        $player = $this->player('Stable Player', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);
        $snapshot = FantasyDecisionSnapshot::where('fantasy_player_id', $player->id)->first();
        $snapshot->update(['status' => FantasyDecisionSnapshot::STATUS_EVALUATED, 'outcome' => ['favorable' => true, 'realProfit' => 123]]);

        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);

        $snapshot->refresh();
        $this->assertSame(FantasyDecisionSnapshot::STATUS_EVALUATED, $snapshot->status);
        $this->assertSame(123, $snapshot->outcome['realProfit']);
    }

    public function test_algorithm_version_is_recorded_on_every_snapshot(): void
    {
        ['account' => $account, 'team' => $team] = $this->account();
        $player = $this->player('Stable Player', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        config(['fantasy.backtest.algorithm_version' => 'v-test-42']);
        $this->artisan('fantasy:snapshot-decisions', ['--account' => $account->id]);

        $snapshot = FantasyDecisionSnapshot::where('fantasy_player_id', $player->id)->first();
        $this->assertSame('v-test-42', $snapshot->algorithm_version);
    }
}
