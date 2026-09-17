<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlayerControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{user: User, account: FantasyAccount, league: FantasyLeague, team: FantasyTeam} */
    private function setupFixture(int $cash = 20_000_000): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return ['user' => $user, 'account' => $account->fresh(), 'league' => $league, 'team' => $team];
    }

    private function player(string $name, string $position, int $marketValue, array $extra = []): FantasyPlayer
    {
        return FantasyPlayer::create(array_merge([
            'external_id' => uniqid(),
            'name' => $name,
            'position' => $position,
            'market_value' => $marketValue,
            'average_points' => 5.0,
            'points' => 50,
            'status' => 'ok',
        ], $extra));
    }

    /** @param  array<int, float>  $factorsByDaysAgo */
    private function riseSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create(['market_value' => (int) ($currentValue / $factor), 'captured_at' => Carbon::now()->subDays($daysAgo)]);
        }
    }

    /** @param  array<int, float>  $factorsByDaysAgo */
    private function fallSnapshots(FantasyPlayer $player, int $currentValue, array $factorsByDaysAgo): void
    {
        foreach ($factorsByDaysAgo as $daysAgo => $factor) {
            $player->snapshots()->create(['market_value' => (int) ($currentValue * $factor), 'captured_at' => Carbon::now()->subDays($daysAgo)]);
        }
    }

    private function marketListing(FantasyLeague $league, FantasyPlayer $player, int $marketValue, ?int $askingPrice = null): FantasyMarketPlayer
    {
        return FantasyMarketPlayer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => $marketValue,
            'asking_price' => $askingPrice ?? $marketValue,
            'is_on_market' => true,
        ]);
    }

    /** 1. My own player never shows a buy-style decision. */
    public function test_1_own_player_never_shows_a_buy_decision(): void
    {
        ['user' => $user, 'team' => $team] = $this->setupFixture();
        $player = $this->player('Mine', 'MF', 10_000_000);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('OWNED_BY_ME', $response->json('context'));
        $this->assertSame('ROSTER', $response->json('decision.type'));
        $this->assertNotSame('BUY', $response->json('decision.type'));
    }

    /** 2. A market player's decision uses a real Buy Economic Score. */
    public function test_2_market_player_uses_real_buy_economic_score(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('On Market', 'FW', 10_000_000);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->marketListing($league, $player, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('ON_MARKET', $response->json('context'));
        $this->assertSame('BUY', $response->json('decision.type'));
        $this->assertIsInt($response->json('decision.mainScore'));
        $this->assertGreaterThan(0, $response->json('decision.mainScore'));
    }

    /** 3. A rival's player uses the clause economic analysis. */
    public function test_3_rival_player_uses_clause_analysis(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Rival Player', 'DF', 8_000_000);
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Rival']);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rivalTeam->id, 'fantasy_player_id' => $player->id, 'clause_value' => 8_700_000]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('OWNED_BY_RIVAL', $response->json('context'));
        $this->assertSame('CLAUSE', $response->json('decision.type'));
        $this->assertArrayHasKey('clauseEconomicScore', $response->json('decision.raw'));
    }

    /** 4. RecommendedBid never exceeds MaxBid. */
    public function test_4_recommended_bid_never_exceeds_max_bid(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Fair Deal', 'MF', 10_000_000);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);
        $this->marketListing($league, $player, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $recommendedBid = $response->json('decision.raw.recommendedBid');
        $maxBid = $response->json('decision.raw.maxBid');

        if (is_numeric($recommendedBid)) {
            $this->assertLessThanOrEqual($maxBid, $recommendedBid);
        } else {
            $this->assertSame('DO_NOT_CHASE', $recommendedBid);
        }
    }

    /** 5. estimatedWinningBid above MaxBid always resolves to DO_NOT_CHASE. */
    public function test_5_estimated_winning_bid_above_max_bid_is_do_not_chase(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Overheated', 'MF', 10_000_000);
        // Flat/negative trend -> low projectedValue14d -> low MaxBid, while the
        // league's real auction history (below) implies a huge winning premium.
        $this->fallSnapshots($player, 10_000_000, [1 => 1.01, 3 => 1.02, 7 => 1.03]);
        $this->marketListing($league, $player, 10_000_000);

        for ($i = 0; $i < 5; $i++) {
            $comp = $this->player("Comp {$i}", 'MF', 10_000_000);
            $comp->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(2)]);
            $buyer = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => "Buyer {$i}"]);
            FantasyOffer::create([
                'fantasy_league_id' => $league->id, 'fantasy_player_id' => $comp->id, 'offering_team_id' => $buyer->id,
                'amount' => 40_000_000, 'status' => FantasyOffer::STATUS_ACCEPTED, 'type' => 'MARKET_BID',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $this->assertSame('DO_NOT_CHASE', $response->json('decision.action'));
        $this->assertSame('DO_NOT_CHASE', $response->json('decision.raw.recommendedBid'));
    }

    /** 6. A high Trade Score with a real offer beating the projection sells for profit. */
    public function test_6_high_trade_score_with_a_good_offer_sells_for_profit(): void
    {
        // Tight capital (low cash) so the base Sell Score isn't purely
        // trade-driven — a real Trade Bonus only has to tip an already
        // plausible sell over Hold's score, never manufacture one from zero.
        ['user' => $user, 'league' => $league, 'team' => $team] = $this->setupFixture(cash: 3_000_000);
        $player = $this->player('Trade Candidate', 'FW', 8_000_000, ['average_points' => 2.0, 'points' => 20]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id]);
        // Strong prior rise now decelerating -> high Trade Score.
        $player->snapshots()->create(['market_value' => (int) (8_000_000 / 1.0002), 'captured_at' => Carbon::now()->subDay()]);
        $player->snapshots()->create(['market_value' => (int) (8_000_000 / (1.002 ** 3)), 'captured_at' => Carbon::now()->subDays(3)]);
        $player->snapshots()->create(['market_value' => (int) (8_000_000 / (1.02 ** 7)), 'captured_at' => Carbon::now()->subDays(7)]);
        FantasyOffer::create([
            'fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id, 'receiving_team_id' => $team->id,
            'amount' => 11_000_000, 'status' => FantasyOffer::STATUS_PENDING, 'type' => 'DIRECT_OFFER',
        ]);
        // A cheap, similarly-scored alternative on the market — real "upgrade" signal.
        $alternative = $this->player('Cheap Alt', 'FW', 3_000_000);
        $this->marketListing($league, $alternative, 3_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $this->assertSame('SELL', $response->json('decision.action'));
        $this->assertSame('TRADE_PROFIT', $response->json('decision.raw.sellReasonCode'));
    }

    /** 7. A high Clause Score with the unlock still far away holds, planned. */
    public function test_7_good_clause_score_far_from_unlock_holds(): void
    {
        ['user' => $user, 'team' => $team] = $this->setupFixture();
        $player = $this->player('Cheap Clause Far', 'MF', 20_000_000);
        FantasyTeamPlayer::create([
            'fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id,
            'clause_value' => 20_200_000, 'clause_locked_until' => Carbon::now()->addDays(10),
        ]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $this->assertSame('HOLD', $response->json('decision.action'));
        $this->assertTrue($response->json('decision.raw.clauseTiming.shouldRaise'));
        $this->assertFalse($response->json('decision.raw.clauseTiming.shouldRaiseNow'));
    }

    /** 8. A high Clause Score with the unlock imminent raises the clause. */
    public function test_8_good_clause_score_near_unlock_raises(): void
    {
        ['user' => $user, 'team' => $team] = $this->setupFixture();
        $player = $this->player('Cheap Clause Near', 'MF', 20_000_000);
        FantasyTeamPlayer::create([
            'fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id,
            'clause_value' => 20_200_000, 'clause_locked_until' => Carbon::now()->addHours(4),
        ]);
        $this->riseSnapshots($player, 20_000_000, [1 => 1.01, 3 => 1.01 ** 3, 7 => 1.01 ** 7]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $this->assertSame('LOCK_CLAUSE', $response->json('decision.action'));
        $this->assertTrue($response->json('decision.raw.clauseTiming.shouldRaiseNow'));
    }

    /** 9. Incomplete history lowers dataQuality/confidence rather than pretending it's complete. */
    public function test_9_incomplete_history_lowers_confidence(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $full = $this->player('Full History', 'MF', 10_000_000);
        $this->riseSnapshots($full, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->marketListing($league, $full, 10_000_000);

        $partial = $this->player('Partial History', 'MF', 10_000_000);
        $partial->snapshots()->create(['market_value' => 9_800_000, 'captured_at' => Carbon::now()->subDay()]);
        $this->marketListing($league, $partial, 10_000_000);

        $fullResponse = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$full->id}");
        $partialResponse = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$partial->id}");

        $this->assertLessThan($fullResponse->json('decision.confidence'), $partialResponse->json('decision.confidence'));
    }

    /**
     * 10. No data anywhere never invents values: a FREE player still gets a
     * hypothetical buy-style decision (reusing its own market value as a
     * stand-in acquisition price), but with dataQuality/confidence at 0 and
     * a flat (not fabricated) growth assumption — never a null decision.
     */
    public function test_10_no_data_never_invents_values(): void
    {
        ['user' => $user] = $this->setupFixture();
        $player = $this->player('No Data At All', 'MF', 5_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('FREE', $response->json('context'));
        $this->assertSame('BUY', $response->json('decision.type'));
        $this->assertSame(0, $response->json('decision.confidence'));
        $this->assertSame($response->json('decision.raw.currentMarketValue'), $response->json('decision.raw.acquisitionPrice'));
        $this->assertNull($response->json('trend.change24h'));
        $this->assertNull($response->json('trend.pctChange7d'));
    }

    /** 12. A FREE player with real trend history gets a real hypothetical Buy Economic Score, not a placeholder. */
    public function test_12_free_player_gets_a_hypothetical_buy_decision(): void
    {
        ['user' => $user] = $this->setupFixture();
        $player = $this->player('Free Riser', 'FW', 10_000_000);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('FREE', $response->json('context'));
        $this->assertSame('BUY', $response->json('decision.type'));
        $this->assertGreaterThan(0, $response->json('decision.confidence'));
        $this->assertGreaterThan(0, $response->json('decision.raw.expectedROI14d'));
    }

    /** 11. Favors and risks are derived from real metrics, not hardcoded strings. */
    public function test_11_favors_and_risks_are_derived_from_metrics(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Great Deal', 'MF', 10_000_000);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->marketListing($league, $player, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $favors = $response->json('decision.favors');
        $this->assertNotEmpty($favors);
        $roi = $response->json('decision.raw.expectedROI14d');
        $this->assertStringContainsString(number_format($roi * 100, 1), collect($favors)->implode(' '));
    }

    /** 13. Projections reuse the same engine's projected values, never a second computation. */
    public function test_13_projections_reuse_the_existing_calculation(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Projected', 'MF', 10_000_000);
        $this->riseSnapshots($player, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->marketListing($league, $player, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $this->assertSame($response->json('decision.raw.projectedValue14d'), $response->json('projections.value14d'));
    }

    /** 14. Alternatives are ranked by real economics, not a hardcoded list. */
    public function test_14_alternatives_are_ranked_by_real_economics(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Subject', 'DF', 10_000_000);
        $this->marketListing($league, $player, 10_000_000);

        $goodAlt = $this->player('Good Alternative', 'DF', 10_000_000);
        $this->riseSnapshots($goodAlt, 10_000_000, [1 => 1.02, 3 => 1.02 ** 3, 7 => 1.02 ** 7]);
        $this->marketListing($league, $goodAlt, 10_000_000);

        $badAlt = $this->player('Bad Alternative', 'DF', 10_000_000);
        $this->fallSnapshots($badAlt, 10_000_000, [1 => 1.01, 3 => 1.02, 7 => 1.03]);
        $this->marketListing($league, $badAlt, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $names = collect($response->json('alternatives'))->pluck('name')->values()->all();
        $this->assertSame('Good Alternative', $names[0]);
    }

    /** 15. The same player switches visible blocks correctly when its context changes. */
    public function test_15_context_change_switches_the_decision_block(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Changes Context', 'MF', 10_000_000);

        $free = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");
        $this->assertSame('FREE', $free->json('context'));
        $this->assertSame('BUY', $free->json('decision.type'));

        $this->marketListing($league, $player, 10_000_000);

        $onMarket = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");
        $this->assertSame('ON_MARKET', $onMarket->json('context'));
        $this->assertSame('BUY', $onMarket->json('decision.type'));
    }

    /**
     * 16. Regression test for a real production bug: fantasy_players is a
     * global catalog shared by every account, but ownership is only ever
     * true within one league. show() used to look up the *latest*
     * FantasyTeamPlayer row for a player id with no league scope at all —
     * so a real player rostered in someone else's account/league (whoever
     * happened to sync most recently) could win over the row for the
     * league you're actually viewing, leaking their context/clause/owner
     * into your page. Confirmed live with two real separate users.
     */
    public function test_16_a_player_owned_in_another_accounts_league_never_leaks_into_this_one(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Shared Across Leagues', 'MF', 10_000_000);

        // A completely different account, different league, owns this same
        // real player and — crucially — synced *after* the fixture above,
        // so its FantasyTeamPlayer row has the higher id.
        $strangerUser = User::factory()->create();
        $strangerAccount = FantasyAccount::create(['user_id' => $strangerUser->id]);
        $strangerLeague = FantasyLeague::create(['fantasy_account_id' => $strangerAccount->id, 'external_id' => uniqid(), 'name' => 'Someone Elses League']);
        $strangerTeam = FantasyTeam::create(['fantasy_league_id' => $strangerLeague->id, 'external_id' => uniqid(), 'name' => 'Their Team', 'is_mine' => true]);
        FantasyTeamPlayer::create(['fantasy_team_id' => $strangerTeam->id, 'fantasy_player_id' => $player->id, 'clause_value' => 99_000_000]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        // Not on any team in *my* league -> FREE, never the stranger's context/clause.
        $response->assertOk();
        $this->assertSame('FREE', $response->json('context'));
        $this->assertNull($response->json('owner'));
        $this->assertNull($response->json('clauseValue'));

        // Same guarantee for the paginated list (index()) — only this one
        // player exists in this test, so it's always data.0.
        $list = $this->actingAs($user, 'sanctum')->getJson('/api/players');
        $list->assertOk();
        $this->assertNull($list->json('data.0.owner'));
        $this->assertNull($list->json('data.0.clauseValue'));
    }

    /**
     * 17. A rival's player who's ALSO listed on the market still exposes a
     * clause value/owner and a clausePurchaseOrder slot — paying a clause
     * works independently of a market listing, so context flipping to
     * ON_MARKET (the more actionable card to show) must never hide it.
     */
    public function test_17_a_rival_player_also_on_the_market_still_allows_scheduling_a_clause_order(): void
    {
        ['user' => $user, 'league' => $league] = $this->setupFixture();
        $player = $this->player('Listed Rival Player', 'FW', 10_000_000);
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Rival']);
        FantasyTeamPlayer::create(['fantasy_team_id' => $rivalTeam->id, 'fantasy_player_id' => $player->id, 'player_team_id' => 'PT-1', 'clause_value' => 9_000_000]);
        $this->marketListing($league, $player, 10_000_000);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/players/{$player->id}");

        $response->assertOk();
        $this->assertSame('ON_MARKET', $response->json('context'));
        $this->assertSame(9_000_000, $response->json('clauseValue'));
        $this->assertFalse($response->json('owner.isMine'));
        $this->assertArrayHasKey('clausePurchaseOrder', $response->json());

        $order = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $player->id]);
        $order->assertCreated();
    }
}
