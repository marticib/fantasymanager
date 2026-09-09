<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyDecisionSnapshot;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FantasyEvaluateDecisionsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function account(): FantasyAccount
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return $account->fresh();
    }

    private function player(): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Test Player', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function snapshot(FantasyAccount $account, FantasyPlayer $player, array $overrides = []): FantasyDecisionSnapshot
    {
        return FantasyDecisionSnapshot::create(array_merge([
            'fantasy_account_id' => $account->id,
            'fantasy_player_id' => $player->id,
            'fantasy_league_id' => $account->active_league_id,
            'decision_type' => FantasyDecisionSnapshot::TYPE_MARKET_BUY,
            'action' => 'BUY',
            'current_market_value' => 10_000_000,
            'reference_value' => 10_000_000,
            'projected_value' => 11_000_000,
            'main_score' => 80,
            'confidence' => 90,
            'horizon_days' => 14,
            'snapshot_date' => Carbon::now()->subDays(15)->toDateString(),
            'payload' => [],
            'algorithm_version' => 'test-v1',
            'status' => FantasyDecisionSnapshot::STATUS_PENDING,
            'generated_at' => Carbon::now()->subDays(15),
        ], $overrides));
    }

    private function futureMarketValue(FantasyPlayer $player, Carbon $capturedAt, int $value): void
    {
        $player->snapshots()->create(['market_value' => $value, 'captured_at' => $capturedAt]);
    }

    /** 1. BUY at 10M, value 14d later = 11M -> favorable, +10% ROI. */
    public function test_1_buy_with_profit_is_favorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['reference_value' => 10_000_000]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 11_000_000);

        $this->artisan('fantasy:evaluate-decisions')->assertExitCode(0);

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertSame(FantasyDecisionSnapshot::STATUS_EVALUATED, $snapshot->status);
        $this->assertEquals(1_000_000, $snapshot->outcome['realProfit']);
        $this->assertEqualsWithDelta(0.10, $snapshot->outcome['realRoi'], 0.0001);
        $this->assertTrue($snapshot->outcome['favorable']);
    }

    /** 2. BUY at 10M, value 14d later = 9M -> unfavorable. */
    public function test_2_buy_with_loss_is_unfavorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['reference_value' => 10_000_000]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 9_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertSame(FantasyDecisionSnapshot::STATUS_EVALUATED, $snapshot->status);
        $this->assertFalse($snapshot->outcome['favorable']);
    }

    /** 3. SELL at 10M, value 7d later = 8M -> +2M avoided loss, favorable. */
    public function test_3_sell_that_avoided_a_drop_is_favorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, [
            'action' => 'SELL', 'decision_type' => FantasyDecisionSnapshot::TYPE_ROSTER,
            'reference_value' => 10_000_000, 'horizon_days' => 7,
            'snapshot_date' => Carbon::now()->subDays(8)->toDateString(),
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 8_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertSame(FantasyDecisionSnapshot::STATUS_EVALUATED, $snapshot->status);
        $this->assertEquals(2_000_000, $snapshot->outcome['avoidedLoss']);
        $this->assertTrue($snapshot->outcome['favorable']);
    }

    /** 4. SELL at 10M, value 7d later = 12M -> -2M (opportunity loss), unfavorable. */
    public function test_4_sell_that_missed_a_rise_is_unfavorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, [
            'action' => 'SELL', 'decision_type' => FantasyDecisionSnapshot::TYPE_ROSTER,
            'reference_value' => 10_000_000, 'horizon_days' => 7,
            'snapshot_date' => Carbon::now()->subDays(8)->toDateString(),
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 12_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertEquals(-2_000_000, $snapshot->outcome['avoidedLoss']);
        $this->assertEquals(2_000_000, $snapshot->outcome['opportunityLoss']);
        $this->assertFalse($snapshot->outcome['favorable']);
    }

    /** 5. A decision from 3 days ago with a 14-day horizon is not due yet -> stays PENDING. */
    public function test_5_recommendation_not_yet_at_its_horizon_stays_pending(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['snapshot_date' => Carbon::now()->subDays(3)->toDateString(), 'horizon_days' => 14]);

        $this->artisan('fantasy:evaluate-decisions');

        $this->assertSame(FantasyDecisionSnapshot::STATUS_PENDING, FantasyDecisionSnapshot::first()->status);
    }

    /** 6. Horizon has passed but no real snapshot exists near it -> INSUFFICIENT_DATA, never invented. */
    public function test_6_missing_future_snapshot_is_insufficient_data(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player); // due (15 days ago, 14-day horizon), but no snapshot created

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertSame(FantasyDecisionSnapshot::STATUS_INSUFFICIENT_DATA, $snapshot->status);
        $this->assertNull($snapshot->outcome);
    }

    /** 7. An old decision is graded using its own stored data, never re-derived from what the player looks like today. */
    public function test_7_evaluation_uses_stored_reference_never_current_live_data(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['reference_value' => 10_000_000]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 11_000_000);

        // The player's *current* market_value is completely different now —
        // must never leak into the evaluation.
        $player->update(['market_value' => 999_999_999]);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertSame(10_000_000, $snapshot->reference_value);
        $this->assertEquals(1_000_000, $snapshot->outcome['realProfit']);
    }

    /** 8. Changing today's requiredROI config must never alter an already-stored historical grade. */
    public function test_8_changing_current_config_does_not_alter_historical_outcome(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['reference_value' => 10_000_000]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 10_400_000); // +4%

        config(['fantasy.market_buy_analysis.required_roi' => 0.20]); // a stricter threshold today

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        // Still graded on the spec's own break-even bar (>0%), not today's requiredROI.
        $this->assertTrue($snapshot->outcome['favorable']);
        $this->assertEqualsWithDelta(0.04, $snapshot->outcome['realRoi'], 0.0001);
    }

    /** 10. no_look_ahead is folded into 6/7 above; this covers PAY_CLAUSE using BUY's own methodology (spec section 17). */
    public function test_pay_clause_uses_the_same_methodology_as_buy(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, [
            'action' => 'PAY_CLAUSE', 'decision_type' => FantasyDecisionSnapshot::TYPE_RIVAL_CLAUSE,
            'reference_value' => 8_700_000,
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 10_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertEqualsWithDelta(1_300_000, $snapshot->outcome['realProfit'], 1);
        $this->assertTrue($snapshot->outcome['favorable']);
    }

    /** DO_NOT_CHASE: avoiding a bid that would have overpaid is favorable. */
    public function test_do_not_chase_avoiding_an_overpay_is_favorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, [
            'action' => 'DO_NOT_CHASE', 'reference_value' => 11_500_000, 'horizon_days' => 14,
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 10_400_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertEquals(1_100_000, $snapshot->outcome['avoidedLoss']);
        $this->assertTrue($snapshot->outcome['favorable']);
    }

    /** HOLD: value did not fall -> favorable (documented as an approximation). */
    public function test_hold_that_did_not_lose_value_is_favorable(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, [
            'action' => 'HOLD', 'decision_type' => FantasyDecisionSnapshot::TYPE_ROSTER,
            'reference_value' => 10_000_000, 'horizon_days' => 7,
            'snapshot_date' => Carbon::now()->subDays(8)->toDateString(),
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 10_500_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertTrue($snapshot->outcome['favorable']);
        $this->assertArrayHasKey('methodology', $snapshot->outcome);
    }

    /** 17. No look-ahead: only a snapshot at/after the horizon is used, never one from before it. */
    public function test_17_never_uses_a_snapshot_from_before_the_horizon(): void
    {
        $account = $this->account();
        $player = $this->player();
        $this->snapshot($account, $player, ['reference_value' => 10_000_000]);

        // A snapshot taken well before the horizon, with a very different
        // value — must be ignored even though it technically exists.
        $this->futureMarketValue($player, Carbon::now()->subDays(10), 999_000_000);
        // The real, at-horizon value.
        $this->futureMarketValue($player, Carbon::now()->subDay(), 11_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $snapshot = FantasyDecisionSnapshot::first();
        $this->assertEquals(1_000_000, $snapshot->outcome['realProfit']);
    }

    /** NOT_EVALUABLE rows (e.g. RAISE_CLAUSE) are never touched by the evaluation command. */
    public function test_not_evaluable_rows_are_never_graded(): void
    {
        $account = $this->account();
        $player = $this->player();
        $snapshot = $this->snapshot($account, $player, [
            'action' => 'LOCK_CLAUSE', 'status' => FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE,
        ]);
        $this->futureMarketValue($player, Carbon::now()->subDay(), 20_000_000);

        $this->artisan('fantasy:evaluate-decisions');

        $this->assertSame(FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE, $snapshot->fresh()->status);
        $this->assertNull($snapshot->fresh()->outcome);
    }
}
