<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\MarketValueProjector;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlayerDecisionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): PlayerDecisionEngine
    {
        return new PlayerDecisionEngine(
            new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService),
            new FantasySettingsService,
            new MarketValueProjector,
        );
    }

    /** @return array{account: FantasyAccount, league: FantasyLeague, team: FantasyTeam} */
    private function setupAccount(int $cash = 10_000_000, ?int $currentMatchday = 5): array
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id, 'current_matchday' => $currentMatchday]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $account->update(['active_league_id' => $league->id, 'active_team_id' => $team->id]);

        return ['account' => $account->fresh(), 'league' => $league, 'team' => $team];
    }

    private function weekPoints(array $pointsByWeek): array
    {
        return ['weekPoints' => collect($pointsByWeek)->map(fn ($points, $week) => ['weekNumber' => $week, 'points' => $points])->values()->all()];
    }

    private function ownPlayer(FantasyTeam $team, string $name, array $attrs, ?int $clauseValue = null, array $teamPlayerAttrs = []): FantasyPlayer
    {
        $player = FantasyPlayer::create(array_merge(['external_id' => uniqid(), 'name' => $name], $attrs));
        FantasyTeamPlayer::create(array_merge([
            'fantasy_team_id' => $team->id,
            'fantasy_player_id' => $player->id,
            'clause_value' => $clauseValue,
        ], $teamPlayerAttrs));

        return $player;
    }

    private function marketListing(FantasyLeague $league, string $name, string $position, int $marketValue, float $averagePoints): FantasyPlayer
    {
        $player = FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => $name,
            'position' => $position,
            'market_value' => $marketValue,
            'average_points' => $averagePoints,
            'points' => (int) ($averagePoints * 10),
            'status' => 'ok',
        ]);

        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => $marketValue,
            'is_on_market' => true,
        ]);

        return $player;
    }

    private function receivedOffer(FantasyLeague $league, FantasyPlayer $player, FantasyTeam $receivingTeam, int $amount): void
    {
        FantasyOffer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'receiving_team_id' => $receivingTeam->id,
            'amount' => $amount,
            'status' => FantasyOffer::STATUS_PENDING,
            'type' => 'DIRECT_OFFER',
        ]);
    }

    /**
     * Historical value = currentValue / factor (factor > 1, e.g. 1.02 for
     * +2%/day) — smaller further back in time, i.e. a *rising* trend.
     *
     * @param  array<int, float>  $factorsByDaysAgo
     */
    private function riseSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) ($currentValue / $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    /**
     * Historical value = currentValue * factor (factor > 1, e.g. 1.03 for
     * -3%/day) — bigger further back in time, i.e. a *falling* trend.
     *
     * @param  array<int, float>  $factorsByDaysAgo
     */
    private function fallSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) ($currentValue * $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    public function test_a_star_player_with_a_small_dip_is_held(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Star Player', [
            'position' => 'MF',
            'market_value' => 40_000_000,
            'average_points' => 8.0,
            'points' => 80,
            'status' => 'ok',
            'raw_payload' => $this->weekPoints([4 => 9, 3 => 10, 2 => 8, 1 => 7]),
        ], clauseValue: 90_000_000); // heavily protected already, raising further is inefficient

        // A tiny, single-day dip — not a real depreciation trend.
        $player->snapshots()->create(['market_value' => 40_400_000, 'captured_at' => Carbon::now()->subDay()]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('HOLD', $result->action);
    }

    public function test_a_mediocre_player_depreciating_fast_is_sold(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 500_000); // cash-strapped

        $player = $this->ownPlayer($team, 'Fading Squad Player', [
            'position' => 'DF',
            'market_value' => 6_000_000,
            'average_points' => 2.5,
            'points' => 25,
            'status' => 'ok',
            'raw_payload' => $this->weekPoints([4 => 1, 3 => 0, 2 => 2, 1 => 1]),
        ], clauseValue: 6_600_000);

        // Falling hard and steadily: -3%/day compounded over the last week.
        foreach ([1 => 1.03, 3 => 1.03 ** 3, 7 => 1.03 ** 7] as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) (6_000_000 * $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        // A cheaper, similarly-productive alternative sitting right there on the market.
        $this->marketListing($league, 'Bargain Replacement', 'DF', 3_500_000, 2.6);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('SELL', $result->action);
        $this->assertGreaterThan($result->holdScore, $result->sellScore);
        $this->assertGreaterThanOrEqual(0, $result->clauseScore);
        $this->assertLessThanOrEqual(100, $result->clauseScore);
    }

    public function test_a_rising_young_player_is_held_not_flagged_for_a_clause_raise(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Rising Youngster', [
            'position' => 'FW',
            'market_value' => 15_000_000,
            'average_points' => 6.5,
            'points' => 65,
            'status' => 'ok',
            'raw_payload' => $this->weekPoints([4 => 8, 3 => 7, 2 => 6, 1 => 5]),
            // Already well protected: clause is far above market value, so raising it further is inefficient.
        ], clauseValue: 35_000_000);

        // +2%/day compounded: historical value = current / 1.02^daysAgo (smaller further back = rising).
        foreach ([1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7] as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) (15_000_000 / $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('HOLD', $result->action);
    }

    public function test_a_valuable_player_with_a_cheap_clause_is_flagged_to_raise_it(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Undervalued Asset', [
            'position' => 'MF',
            'market_value' => 30_000_000,
            'average_points' => 6.0,
            'points' => 60,
            'status' => 'ok',
            'raw_payload' => $this->weekPoints([4 => 6, 3 => 6, 2 => 5, 1 => 6]),
        ], clauseValue: 30_500_000); // clause barely above market value — trivial for anyone to trigger

        // Rising steadily (+2%/day), making the cheap clause an even better target for a rival.
        foreach ([1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7] as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) (30_000_000 / $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('LOCK_CLAUSE', $result->action);
        $this->assertGreaterThan($result->holdScore, $result->clauseScore);
        $this->assertGreaterThan($result->sellScore, $result->clauseScore);
        $this->assertNotNull($result->metrics['clausePremiumPct']);
        $this->assertLessThan(5.0, $result->metrics['clausePremiumPct']);
    }

    /**
     * A cheap, already-unprotected clause can legitimately outscore Hold on
     * theft risk and premium efficiency alone even while the player's value
     * is actually declining — but the explanation must never claim the
     * value is "still rising" in that situation (real bug: it used to,
     * unconditionally, for every LOCK_CLAUSE verdict).
     */
    public function test_a_cheap_unprotected_clause_is_raised_without_claiming_a_rise_it_is_not_having(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Undervalued But Falling', [
            'position' => 'MF',
            'market_value' => 30_000_000,
            'average_points' => 6.0,
            'points' => 60,
            'status' => 'ok',
            'raw_payload' => $this->weekPoints([4 => 6, 3 => 6, 2 => 5, 1 => 6]),
        ], clauseValue: 30_500_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->subDays(16)]); // clause barely above market value, protection already expired

        // Falling gently (-0.2%/day) — the opposite of what the old,
        // unconditional "still rising" reason text claimed.
        $this->fallSnapshots($player, 30_000_000, [1 => 1.002, 3 => 1.002 ** 3, 7 => 1.002 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('LOCK_CLAUSE', $result->action);
        $this->assertLessThan(0, $result->trade['appreciation7d']);
        $this->assertStringNotContainsString("l'alça", $result->reason);
        $this->assertStringContainsString('baixa', $result->reason);
    }

    /**
     * Regression test for the real bug this comes from: a roster player's
     * `raw_payload.weekPoints` is a bare scalar (not the `[{weekNumber,
     * points}]` shape `expectedWeeklyPoints()` expects) whenever the last
     * sync went through the roster/lineup endpoint — which is every owned
     * player. Reading only `weekPoints` silently saw zero played weeks for
     * the entire roster, flooring `starterProbability`/`sportingScore` and
     * skewing Hold artificially low against Sell/LOCK_CLAUSE. The real
     * per-week data was there all along, under `lastStats`.
     */
    public function test_a_player_with_roster_shaped_raw_payload_still_gets_real_recent_form(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Roster Payload Shape', [
            'position' => 'MF',
            'market_value' => 26_566_014,
            'average_points' => 4.75,
            'points' => 19,
            'status' => 'ok',
            // Roster/lineup shape: `weekPoints` a bare scalar, real per-week
            // breakdown under `lastStats.totalPoints` instead.
            'raw_payload' => [
                'weekPoints' => 9,
                'lastStats' => [
                    ['weekNumber' => 1, 'totalPoints' => 4],
                    ['weekNumber' => 2, 'totalPoints' => 5],
                    ['weekNumber' => 3, 'totalPoints' => 1],
                    ['weekNumber' => 4, 'totalPoints' => 9],
                ],
            ],
        ], clauseValue: 32_334_415, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->subDays(16)]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        // All 4 recent weeks were actually played (real points, none zero) —
        // starterProbability must reflect that, not the pre-fix 30% floor.
        $this->assertGreaterThan(80, $result->metrics['starterProbability']);
    }

    public function test_a_player_with_insufficient_data_is_held_with_low_confidence(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(currentMatchday: null);

        // No snapshots, no external trend, no weekPoints, no clause, no market listings anywhere.
        $player = $this->ownPlayer($team, 'Blank Slate', [
            'position' => 'MF',
            'market_value' => 5_000_000,
            'average_points' => 0,
            'points' => 0,
            'status' => 'ok',
        ]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertSame('HOLD', $result->action);
        $this->assertLessThan(50, $result->confidence);
    }

    public function test_rejects_weights_that_do_not_sum_to_one(): void
    {
        config(['fantasy.player_decision.hold_weights' => ['sporting' => 0.5, 'market' => 0.5, 'scarcity' => 0.5, 'clause_protection' => 0.5]]);

        $this->expectException(\InvalidArgumentException::class);

        $this->engine();
    }

    // --- Trade Score -------------------------------------------------------

    public function test_trade_1_a_player_still_accelerating_gets_a_low_or_moderate_trade_score(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Still Accelerating', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Today's daily pace (+3%) is *faster* than the last 3 days' (+1%) — still building momentum, not exhausted.
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / 1.03), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.01 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.015 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertLessThan(40, $result->tradeScore);
        $this->assertSame(0, $result->trade['components']['momentumExhaustion']);
        $this->assertNotSame('SELL', $result->action, 'should not be nudged into selling early just because he has risen');
    }

    public function test_trade_2_a_player_who_rose_a_lot_but_is_decelerating_gets_a_high_trade_score(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Losing Steam', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Exact figures from the spec's own example: growth7d +2.1%/day, growth3d +1.8%/day, growth1d +0.4%.
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / 1.004), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.018 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (20_000_000 / (1.021 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertGreaterThan(60, $result->trade['components']['momentumExhaustion']);
        $this->assertGreaterThan(55, $result->tradeScore);
    }

    public function test_trade_3_a_falling_player_gets_a_low_trade_score_even_if_sell_score_is_high(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 500_000);

        $player = $this->ownPlayer($team, 'Just Depreciating', [
            'position' => 'DF', 'market_value' => 6_000_000, 'average_points' => 2.0, 'points' => 20, 'status' => 'ok',
        ]);
        $this->fallSnapshots($player, 6_000_000, [1 => 1.03, 3 => 1.03 ** 3, 7 => 1.03 ** 7]); // -3%/day compounded

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        // Clearly not a trading opportunity — well below the trading cases
        // (tests 2/4/8 above score 55-90+); driven only by "no upside left",
        // never by appreciation or momentum, both correctly zero.
        $this->assertLessThan(50, $result->tradeScore);
        $this->assertNotContains($result->trade['classification'], ['SELL_FOR_PROFIT', 'STRONG_SELL']);
        $this->assertSame(0, $result->trade['components']['appreciation']);
        $this->assertSame(0, $result->trade['components']['momentumExhaustion'], 'no prior uptrend to exhaust — this is depreciation, not trading');
        if ($result->action === 'SELL') {
            $this->assertSame('DEPRECIATION', $result->sellReasonCode);
        }
    }

    public function test_trade_4_a_big_offer_over_a_stagnant_trend_gets_a_very_high_trade_score(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Flat But Wanted', [
            'position' => 'FW', 'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Nearly flat: nudging up 1%/day over the week, essentially nothing today.
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / 1.000), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.002 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.01 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);
        $this->receivedOffer($league, $player, $team, 11_500_000); // +15% over market value

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertGreaterThanOrEqual(70, $result->tradeScore);
        $this->assertContains($result->trade['classification'], ['SELL_FOR_PROFIT', 'STRONG_SELL']);
    }

    public function test_trade_5_a_big_offer_with_a_bigger_projected_upside_does_not_force_strong_sell(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Still Climbing Fast', [
            'position' => 'FW', 'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        // Accelerating (growth1d > growth3d), and a steep growth7d so the projected 7-day upside lands around +20%.
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / 1.02), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.01 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.0675 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);
        $this->receivedOffer($league, $player, $team, 11_000_000); // +10% over market value

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertGreaterThan(0.15, $result->trade['expectedUpside7d']);
        $this->assertNotSame('STRONG_SELL', $result->trade['classification']);
        $this->assertLessThan(75, $result->tradeScore);
    }

    public function test_trade_6_no_current_offer_is_never_invented_and_the_engine_still_works(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'No Offers Here', [
            'position' => 'MF', 'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertNull($result->trade['currentOffer']);
        $this->assertNull($result->trade['components']['sellPremium']);
        $this->assertGreaterThanOrEqual(0, $result->tradeScore);
        $this->assertLessThanOrEqual(100, $result->tradeScore);
    }

    public function test_trade_7_no_value_history_at_all_does_not_invent_appreciation(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(currentMatchday: null);

        $player = $this->ownPlayer($team, 'Totally Fresh', [
            'position' => 'MF', 'market_value' => 8_000_000, 'average_points' => 0, 'points' => 0, 'status' => 'ok',
        ]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertNull($result->trade['appreciation7d']);
        $this->assertNull($result->trade['components']['appreciation']);
        $this->assertNull($result->trade['components']['momentumExhaustion']);
        $this->assertLessThan(50, $result->trade['dataQuality']);
        $this->assertGreaterThanOrEqual(0, $result->tradeScore);
    }

    public function test_trade_8_a_much_better_roi_alternative_on_the_market_raises_capital_efficiency(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Low Roi Here', [
            'position' => 'MF', 'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // ~+1% projected over 7 days.
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / 1.0014), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.0014 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (10_000_000 / (1.0014 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);

        $alt = $this->marketListing($league, 'High Roi Alternative', 'MF', 9_000_000, 5.0);
        // ~+9% projected over 7 days.
        $alt->snapshots()->create(['market_value' => (int) (9_000_000 / 1.0128), 'captured_at' => Carbon::now()->subDay()]);
        $alt->snapshots()->create(['market_value' => (int) (9_000_000 / (1.0128 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $alt->snapshots()->create(['market_value' => (int) (9_000_000 / (1.0128 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertGreaterThanOrEqual(80, $result->trade['components']['capitalEfficiency']);
    }

    public function test_trade_9_the_bonus_never_reduces_the_sell_score(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 500_000);

        $player = $this->ownPlayer($team, 'Any Player', [
            'position' => 'DF', 'market_value' => 6_000_000, 'average_points' => 2.5, 'points' => 25, 'status' => 'ok',
        ]);
        $this->fallSnapshots($player, 6_000_000, [1 => 1.03, 3 => 1.03 ** 3, 7 => 1.03 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertGreaterThanOrEqual($result->sellScore, $result->adjustedSellScore);
    }

    public function test_trade_10_the_adjusted_sell_score_never_exceeds_100(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 0);

        $player = $this->ownPlayer($team, 'Extreme Case', [
            'position' => 'FW', 'market_value' => 10_000_000, 'average_points' => 1.0, 'points' => 10, 'status' => 'ok',
        ]);
        // Flat trend + a huge offer -> a large trade bonus stacked onto an already-high sell score (broke team).
        $player->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(7)]);
        $this->receivedOffer($league, $player, $team, 20_000_000);
        $this->marketListing($league, 'Cheap Similar', 'FW', 2_000_000, 1.0);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertLessThanOrEqual(100, $result->adjustedSellScore);
    }

    public function test_trade_11_trade_is_never_a_final_action(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Whatever Player', [
            'position' => 'FW', 'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        $this->receivedOffer($league, $player, $team, 12_000_000);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertContains($result->action, ['HOLD', 'SELL', 'LOCK_CLAUSE']);
    }

    // --- Clause timing -------------------------------------------------------

    public function test_clause_timing_1_a_good_clause_score_with_days_left_waits(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Cheap Clause Far From Unlock', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ], clauseValue: 20_200_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->addDays(10)]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertTrue($result->clauseTiming['shouldRaise']);
        $this->assertFalse($result->clauseTiming['shouldRaiseNow']);
        $this->assertSame('HOLD', $result->action);
    }

    public function test_clause_timing_2_the_last_protected_day_triggers_raise_clause_now(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Cheap Clause Near Unlock', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ], clauseValue: 20_200_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->addHours(20)]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertTrue($result->clauseTiming['shouldRaiseNow']);
        $this->assertSame('LOCK_CLAUSE', $result->action);
    }

    public function test_clause_timing_3_an_already_unlocked_clause_raises_immediately(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Cheap And Already Exposed', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ], clauseValue: 20_200_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->subDay()]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertFalse($result->clauseTiming['locked']);
        $this->assertTrue($result->clauseTiming['shouldRaiseNow']);
        $this->assertSame('LOCK_CLAUSE', $result->action);
    }

    public function test_clause_timing_4_recomputes_from_scratch_and_can_cancel_a_planned_raise(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Changes Its Mind', [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ], clauseValue: 20_200_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->addDays(7)]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $day1 = $this->engine()->evaluateRoster($account)->get($player->id);
        $this->assertTrue($day1->clauseTiming['shouldRaise']);
        $this->assertFalse($day1->clauseTiming['shouldRaiseNow']);

        // Six days later: the clause is about to unlock, but the player has since
        // tanked in value (premium now huge, no longer worth protecting).
        $player->update(['market_value' => 20_000_000]);
        FantasyTeamPlayer::where('fantasy_player_id', $player->id)->update([
            'clause_value' => 32_000_000,
            'clause_locked_until' => Carbon::now()->addHours(10),
        ]);

        $day2 = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertNotSame('LOCK_CLAUSE', $day2->action);
    }

    public function test_clause_timing_5_a_poor_clause_score_never_raises_regardless_of_timing(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount(cash: 20_000_000);

        $player = $this->ownPlayer($team, 'Already Well Protected', [
            'position' => 'MF', 'market_value' => 10_000_000, 'average_points' => 4.0, 'points' => 40, 'status' => 'ok',
        ], clauseValue: 18_000_000, teamPlayerAttrs: ['clause_locked_until' => Carbon::now()->addHours(3)]); // huge premium, poor clause score

        $result = $this->engine()->evaluateRoster($account)->get($player->id);

        $this->assertFalse($result->clauseTiming['shouldRaise']);
        $this->assertFalse($result->clauseTiming['shouldRaiseNow']);
        $this->assertNotSame('LOCK_CLAUSE', $result->action);
    }
}
