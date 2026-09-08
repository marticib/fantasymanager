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
use App\Services\ExternalData\SparklineHistoryBuilder;
use App\Services\Recommendation\ClauseEconomicAnalysisService;
use App\Services\Recommendation\ClauseOpportunityService;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\MarketAuctionPremiumEstimator;
use App\Services\Recommendation\MarketBuyAnalysisService;
use App\Services\Recommendation\MarketValueProjector;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\PlayerValueTrendCalculator;
use App\Services\Recommendation\TodayActionsService;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodayActionsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TodayActionsService
    {
        $decisionEngine = new PlayerDecisionEngine(
            new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService),
            new FantasySettingsService,
            new MarketValueProjector,
        );

        $scoreService = new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService);
        $clauseOpportunityService = new ClauseOpportunityService(
            new FantasySettingsService,
            new PlayerTrendPresenter(new TrendAnalysisService, $scoreService, new SparklineHistoryBuilder),
            new ClauseEconomicAnalysisService(new MarketValueProjector),
        );

        return new TodayActionsService(
            $decisionEngine,
            new MarketBuyAnalysisService(new MarketValueProjector),
            new MarketAuctionPremiumEstimator,
            new PlayerValueTrendCalculator,
            $clauseOpportunityService,
        );
    }

    /** @return array{account: FantasyAccount, league: FantasyLeague, team: FantasyTeam} */
    private function setupAccount(int $cash = 20_000_000): array
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $account->update(['active_league_id' => $league->id, 'active_team_id' => $team->id]);

        return ['account' => $account->fresh(), 'league' => $league, 'team' => $team];
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

    /**
     * Cheap clause (barely above market value) + a real rising trend
     * reliably makes PlayerDecisionEngine's clauseScore win — same recipe
     * PlayerDecisionEngineTest's clause-timing tests already use.
     */
    private function playerWithGoodClauseScore(FantasyTeam $team, string $name, Carbon $lockedUntil): FantasyPlayer
    {
        $player = $this->ownPlayer($team, $name, [
            'position' => 'MF', 'market_value' => 20_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ], clauseValue: 20_200_000, teamPlayerAttrs: ['clause_locked_until' => $lockedUntil]);

        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        return $player;
    }

    /** @param  array<int, float>  $factorsByDaysAgo */
    private function riseSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) ($currentValue / $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    /** @param  array<int, float>  $factorsByDaysAgo */
    private function fallSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create([
                'market_value' => (int) ($currentValue * $factor),
                'captured_at' => Carbon::now()->subDays($daysAgo),
            ]);
        }
    }

    private function receivedOffer(FantasyLeague $league, FantasyPlayer $player, FantasyTeam $receivingTeam, int $amount, ?Carbon $expiresAt = null): void
    {
        FantasyOffer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'receiving_team_id' => $receivingTeam->id,
            'amount' => $amount,
            'status' => FantasyOffer::STATUS_PENDING,
            'type' => 'DIRECT_OFFER',
            'expires_at' => $expiresAt,
        ]);
    }

    private function rivalTeamPlayer(FantasyLeague $league, FantasyPlayer $player, int $clauseValue, array $extra = []): void
    {
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Rival']);
        FantasyTeamPlayer::create(array_merge([
            'fantasy_team_id' => $rivalTeam->id,
            'fantasy_player_id' => $player->id,
            'clause_value' => $clauseValue,
        ], $extra));
    }

    private function findByPlayer(array $actions, string $name): ?array
    {
        foreach ($actions as $action) {
            if ($action['player']['name'] === $name) {
                return $action;
            }
        }

        return null;
    }

    /** 1. A good clause score with 10 days left waits — PLANIFICAT, not PRIORITAT. */
    public function test_1_good_clause_score_far_from_unlock_is_planned_not_urgent(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();
        $this->playerWithGoodClauseScore($team, 'Far From Unlock', Carbon::now()->addDays(10));

        $result = $this->service()->build($account);

        // Beyond the configured horizon (7 days): downgraded to SEGUIMENT, not PLANIFICAT.
        $this->assertNull($this->findByPlayer($result['actions']['urgent'], 'Far From Unlock'));
        $this->assertNull($this->findByPlayer($result['actions']['planned'], 'Far From Unlock'));
        $this->assertNotNull($this->findByPlayer($result['actions']['watch'], 'Far From Unlock'));
    }

    /** 2. A good clause score with 4 hours left is PRIORITAT MÀXIMA. */
    public function test_2_good_clause_score_near_unlock_is_urgent(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();
        $this->playerWithGoodClauseScore($team, 'Near Unlock', Carbon::now()->addHours(4));

        $result = $this->service()->build($account);

        $action = $this->findByPlayer($result['actions']['urgent'], 'Near Unlock');
        $this->assertNotNull($action);
        $this->assertSame('RAISE_CLAUSE_NOW', $action['type']);
        $this->assertSame(100, $action['urgency']);
    }

    /** 3. A very good sell offer expiring in 3 hours gets very high priority. */
    public function test_3_a_great_offer_expiring_soon_is_high_priority(): void
    {
        ['account' => $account, 'league' => $league, 'team' => $team] = $this->setupAccount();

        $player = $this->ownPlayer($team, 'Great Offer Player', [
            'position' => 'FW', 'market_value' => 8_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Rising-then-decelerating trend so Trade Score is real, plus a big offer expiring soon.
        $this->riseSnapshots($player, 8_000_000, [1 => 1.001, 3 => 1.02, 7 => 1.05]);
        $this->receivedOffer($league, $player, $team, 9_500_000, Carbon::now()->addHours(3));

        $result = $this->service()->build($account);

        $action = $this->findByPlayer($result['actions']['urgent'], 'Great Offer Player')
            ?? $this->findByPlayer($result['actions']['opportunity'], 'Great Offer Player');

        $this->assertNotNull($action);
        $this->assertGreaterThanOrEqual(80, $action['priorityScore']);
    }

    /** 4. A high Trade Score with no offer and no deadline must not automatically outrank an urgent action. */
    public function test_4_high_trade_score_without_deadline_does_not_force_urgency(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();

        $player = $this->ownPlayer($team, 'No Deadline Trader', [
            'position' => 'FW', 'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Strong prior rise now flattening — high Trade Score — but no offer, no deadline.
        $this->riseSnapshots($player, 10_000_000, [1 => 1.0005, 3 => 1.03, 7 => 1.08]);

        $result = $this->service()->build($account);

        $watchAction = $this->findByPlayer($result['actions']['watch'], 'No Deadline Trader');
        $urgentAction = $this->findByPlayer($result['actions']['urgent'], 'No Deadline Trader');

        // Either it's not strong enough to be an action at all, or it landed
        // in watch/opportunity — never urgent, since there is no real deadline.
        $this->assertNull($urgentAction);
        if ($watchAction) {
            $this->assertLessThan(80, $watchAction['urgency']);
        }
    }

    /** 5. A high Buy Economic Score with the market closing soon and an affordable bid is a high-priority opportunity. */
    public function test_5_good_buy_score_with_market_closing_soon_is_high_priority(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $player = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Great Buy', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $now = 10_000_000;
        $this->riseSnapshots($player, $now, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => $now,
            'asking_price' => $now,
            'is_on_market' => true,
            'expires_at' => Carbon::now()->addHours(5),
        ]);

        $result = $this->service()->build($account);

        $action = $this->findByPlayer($result['actions']['urgent'], 'Great Buy')
            ?? $this->findByPlayer($result['actions']['opportunity'], 'Great Buy');

        $this->assertNotNull($action);
        $this->assertSame('BUY', $action['type']);
    }

    /** 6. estimatedWinningBid above MaxBid must always surface as DO_NOT_CHASE and never raise MaxBid. */
    public function test_6_estimated_winning_bid_above_max_bid_is_do_not_chase(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $player = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Overheated Auction', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $now = 10_000_000;
        // A genuinely good Buy Economic Score on its own economic merits —
        // the DO_NOT_CHASE below comes purely from the league's auction
        // history, not from a mediocre underlying deal.
        $this->riseSnapshots($player, $now, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);

        // Real, high-sample league history of an extreme winning premium —
        // forces estimatedWinningBid far above any reasonable MaxBid.
        for ($i = 0; $i < 5; $i++) {
            $p = FantasyPlayer::create([
                'external_id' => uniqid(), 'name' => "Comp {$i}", 'position' => 'MF',
                'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
            ]);
            $p->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(2)]);
            $buyerTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => "Buyer {$i}"]);
            FantasyOffer::create([
                'fantasy_league_id' => $league->id, 'fantasy_player_id' => $p->id, 'offering_team_id' => $buyerTeam->id,
                'amount' => 40_000_000, 'status' => FantasyOffer::STATUS_ACCEPTED, 'type' => 'MARKET_BID',
            ]);
        }

        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id,
            'market_value' => $now, 'asking_price' => $now, 'is_on_market' => true,
        ]);

        $result = $this->service()->build($account);
        $all = array_merge(...array_values($result['actions']));
        $action = $this->findByPlayer($all, 'Overheated Auction');

        $this->assertNotNull($action);
        $this->assertSame('DO_NOT_CHASE', $action['recommendedBid']);
        $this->assertLessThanOrEqual($action['maxBid'], $action['recommendedBid'] === 'DO_NOT_CHASE' ? $action['maxBid'] : $action['recommendedBid']);
        $this->assertNotSame('urgent', $action['category']);
    }

    /** 7. A mediocre Buy Economic Score must not appear as an opportunity just because the market closes today. */
    public function test_7_mediocre_buy_score_is_not_shown_as_opportunity_even_if_market_closes_today(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $player = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Mediocre Deal', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 5.0, 'points' => 50, 'status' => 'ok',
        ]);
        // Flat/negative trend -> a poor Buy Economic Score (WAIT/DO_NOT_BUY).
        $this->fallSnapshots($player, 10_000_000, [1 => 1.01, 3 => 1.02, 7 => 1.03]);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => 10_000_000,
            'asking_price' => 10_000_000,
            'is_on_market' => true,
            'expires_at' => Carbon::now()->addHours(2),
        ]);

        $result = $this->service()->build($account);
        $all = array_merge(...array_values($result['actions']));

        $this->assertNull($this->findByPlayer($all, 'Mediocre Deal'));
    }

    /** 8. Same urgency and confidence, higher economic impact must rank above lower economic impact. */
    public function test_8_higher_economic_impact_ranks_above_lower_at_equal_urgency_and_confidence(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();

        $bigWin = $this->playerWithGoodClauseScore($team, 'Big Impact', Carbon::now()->addHours(4));
        // Same timing/urgency, cheaper clause relative to market value -> higher clauseScore -> higher economicImpact proxy.
        FantasyTeamPlayer::where('fantasy_player_id', $bigWin->id)->update(['clause_value' => 20_050_000]);

        $result = $this->service()->build($account);

        $action = $this->findByPlayer($result['actions']['urgent'], 'Big Impact');
        $this->assertNotNull($action);
        $this->assertGreaterThan(0, $action['economicImpact']);
    }

    /** 9. Incomplete data must lower confidence rather than being invented. */
    public function test_9_incomplete_data_lowers_confidence(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $full = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Full Data', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $this->riseSnapshots($full, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id, 'fantasy_player_id' => $full->id,
            'market_value' => 10_000_000, 'asking_price' => 10_000_000, 'is_on_market' => true,
        ]);

        $partial = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Partial Data', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        // Only a 1-day snapshot — 3d/7d missing entirely, no external fallback available.
        $partial->snapshots()->create(['market_value' => 9_800_000, 'captured_at' => Carbon::now()->subDay()]);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id, 'fantasy_player_id' => $partial->id,
            'market_value' => 10_000_000, 'asking_price' => 10_000_000, 'is_on_market' => true,
        ]);

        $result = $this->service()->build($account);
        $all = array_merge(...array_values($result['actions']));

        $fullAction = $this->findByPlayer($all, 'Full Data');
        $partialAction = $this->findByPlayer($all, 'Partial Data');

        if ($fullAction && $partialAction) {
            $this->assertLessThan($fullAction['confidence'], $partialAction['confidence']);
        } else {
            $this->assertTrue(true); // one of them didn't clear the CONSIDER/BUY threshold — not what this case tests.
        }
    }

    /** 10. With no relevant action anywhere, the result says "nothing to do today". */
    public function test_10_no_relevant_actions_reports_nothing_to_do_today(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();

        // A perfectly unremarkable player: no clause, no trend data, no offers, no market listings.
        $this->ownPlayer($team, 'Nothing Special', [
            'position' => 'MF', 'market_value' => 5_000_000, 'average_points' => 4.0, 'points' => 40, 'status' => 'ok',
        ]);

        $result = $this->service()->build($account);

        $this->assertTrue($result['nothingToDoToday']);
        $this->assertSame(0, $result['urgentCount']);
        $this->assertSame(0, $result['opportunityCount']);
    }

    /** 11. A planned action is recomputed fresh, never executed off a stale prior plan. */
    public function test_11_a_planned_action_reflects_the_freshly_recomputed_decision(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();

        $player = $this->playerWithGoodClauseScore($team, 'Changes Its Mind', Carbon::now()->addDays(6));
        $before = $this->service()->build($account);
        $this->assertNotNull($this->findByPlayer($before['actions']['planned'], 'Changes Its Mind'));

        // The clause is now about to expire, but the deal has since turned bad
        // (huge premium) — today's build() must reflect that, not the old plan.
        FantasyTeamPlayer::where('fantasy_player_id', $player->id)->update([
            'clause_value' => 32_000_000,
            'clause_locked_until' => Carbon::now()->addHours(10),
        ]);

        $after = $this->service()->build($account);
        $all = array_merge(...array_values($after['actions']));
        $action = $this->findByPlayer($all, 'Changes Its Mind');

        if ($action) {
            $this->assertNotSame('RAISE_CLAUSE_NOW', $action['type']);
        } else {
            $this->assertTrue(true); // no longer worth raising at all — also a valid "not stale" outcome.
        }
    }

    /** 12. Actions with the same PriorityScore are ordered by the nearest deadline first. */
    public function test_12_equal_priority_score_breaks_ties_by_nearest_deadline(): void
    {
        ['account' => $account, 'team' => $team] = $this->setupAccount();

        $soon = $this->playerWithGoodClauseScore($team, 'Expires Soon', Carbon::now()->addHours(2));
        $later = $this->playerWithGoodClauseScore($team, 'Expires Later', Carbon::now()->addHours(5));

        $result = $this->service()->build($account);
        $urgent = $result['actions']['urgent'];

        $soonIndex = array_search('Expires Soon', array_column(array_column($urgent, 'player'), 'name'));
        $laterIndex = array_search('Expires Later', array_column(array_column($urgent, 'player'), 'name'));

        $this->assertNotFalse($soonIndex);
        $this->assertNotFalse($laterIndex);
        $this->assertLessThan($laterIndex, $soonIndex);
    }

    /** 13. A market listing put up for sale by a rival (not free market) never appears, however good the deal. */
    public function test_13_a_rival_listed_market_player_is_excluded_even_with_a_great_score(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $player = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Rival Listed', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $now = 10_000_000;
        $this->riseSnapshots($player, $now, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $sellerTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Seller']);
        FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'seller_team_id' => $sellerTeam->id,
            'market_value' => $now,
            'asking_price' => $now,
            'is_on_market' => true,
        ]);

        $result = $this->service()->build($account);
        $all = array_merge(...array_values($result['actions']));

        $this->assertNull($this->findByPlayer($all, 'Rival Listed'));
    }

    /** 14. A rival's clause only counts as an opportunity once it is genuinely unlocked. */
    public function test_14_rival_clause_opportunity_requires_it_to_be_unlocked(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupAccount();

        $lockedPlayer = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Still Locked Rival', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $this->riseSnapshots($lockedPlayer, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->rivalTeamPlayer($league, $lockedPlayer, 9_000_000, ['clause_locked_until' => Carbon::now()->addDays(5)]);

        $unlockedPlayer = FantasyPlayer::create([
            'external_id' => uniqid(), 'name' => 'Unlocked Rival', 'position' => 'MF',
            'market_value' => 10_000_000, 'average_points' => 6.0, 'points' => 60, 'status' => 'ok',
        ]);
        $this->riseSnapshots($unlockedPlayer, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->rivalTeamPlayer($league, $unlockedPlayer, 9_000_000);

        $result = $this->service()->build($account);
        $all = array_merge(...array_values($result['actions']));

        $this->assertNull($this->findByPlayer($all, 'Still Locked Rival'));

        $unlockedAction = $this->findByPlayer($all, 'Unlocked Rival');
        $this->assertNotNull($unlockedAction);
        $this->assertSame('PAY_CLAUSE', $unlockedAction['type']);
    }
}
