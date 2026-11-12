<?php

namespace Tests\Feature;

use App\Models\FantasySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTradingFixtures;
use Tests\TestCase;

class TradingControllerTest extends TestCase
{
    use BuildsTradingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTrading();
    }

    private function api(string $uri): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->getJson($uri);
    }

    private function makeMoney(string $query = ''): array
    {
        return $this->api('/api/trading/make-money'.$query)->assertOk()->json('data');
    }

    private function plans(string $query = ''): array
    {
        return $this->api('/api/trading/plans'.$query)->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- common

    public function test_both_endpoints_require_authentication(): void
    {
        $this->getJson('/api/trading/make-money')->assertUnauthorized();
        $this->getJson('/api/trading/plans')->assertUnauthorized();
    }

    public function test_invalid_horizon_or_strategy_is_rejected(): void
    {
        $this->api('/api/trading/make-money?horizon=5')->assertStatus(422);
        $this->api('/api/trading/make-money?horizon=30')->assertStatus(422);
    }

    public function test_the_response_carries_horizons_and_the_freshness_picture(): void
    {
        $this->ffRising($this->player('Riser', 'FW', 10_000_000));
        $this->markAllFresh();

        $meta = $this->api('/api/trading/make-money')->assertOk()->json('meta');

        $this->assertSame([3, 7, 14], $meta['horizons']);
        $this->assertSame(14, $meta['defaultHorizon']);
        $this->assertFalse($meta['freshness']['hasStale']);
        $this->assertSame(['league', 'market', 'clauses', 'external'], array_keys($meta['freshness']['sources']));
    }

    public function test_stale_or_missing_sources_raise_freshness_warnings(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->markAllFresh(now()->subHours(20));

        $freshness = $this->api('/api/trading/make-money')->json('meta.freshness');

        $this->assertTrue($freshness['hasStale']);
        $codes = array_column($freshness['warnings'], 'source');
        $this->assertEqualsCanonicalizing(['league', 'market', 'clauses', 'external'], $codes);
    }

    public function test_a_source_that_never_synced_is_reported_as_no_data_not_as_fresh(): void
    {
        $this->myTeam->update(['money' => 1]);
        $this->account->update(['last_synced_at' => null]);

        $freshness = $this->api('/api/trading/make-money')->json('meta.freshness');

        $this->assertSame('NO_DATA', collect($freshness['warnings'])->firstWhere('source', 'market')['code']);
        $this->assertTrue($freshness['sources']['market']['stale']);
        $this->assertNull($freshness['sources']['market']['lastUpdatedAt']);
    }

    public function test_an_account_without_an_active_league_gets_a_clear_state(): void
    {
        $this->account->forceFill(['active_league_id' => null, 'active_team_id' => null])->save();

        $this->assertSame('NO_LEAGUE', $this->makeMoney()['state']);
        $this->assertSame('NO_LEAGUE', $this->plans()['state']);
    }

    public function test_the_response_is_cached_but_a_new_sync_stamp_busts_it(): void
    {
        config(['fantasy.trading.cache_seconds' => 60]);
        $this->ffRising($this->player('Anchor', 'GK', 2_000_000));
        $this->markAllFresh(now()->subMinutes(10));
        $this->assertSame('KEEP_CASH', $this->makeMoney()['state']);

        // New data arrives without any new sync stamp: still the cached answer.
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p, ['fetched_at' => now()->subMinutes(30)]);
        $this->listing($p);
        $this->assertSame('KEEP_CASH', $this->makeMoney()['state']);

        // A fresh market sync changes the stamp → recomputed.
        $this->markAllFresh(now());
        $this->assertSame('OK', $this->makeMoney()['state']);
    }

    // ----------------------------------------------------------- Guanyar diners

    public function test_make_money_buys_a_profitable_market_listing_within_the_budget(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $data = $this->makeMoney();

        $this->assertSame('OK', $data['state']);
        $move = $data['movements'][0];
        $this->assertSame('BUY', $move['type']);
        $this->assertSame($p->id, $move['playerId']);
        $this->assertLessThanOrEqual($move['maxBid'], $move['cost']);
        $this->assertSame($move['cost'], $data['summary']['capitalToInvest']);
        $this->assertSame($move['gain'], $data['summary']['expectedProfit']);
        $this->assertEqualsWithDelta($move['gain'] / $move['cost'], $data['summary']['roi'], 0.0001);
    }

    public function test_make_money_ignores_squad_size_and_positions_entirely(): void
    {
        foreach (['FW', 'FW', 'FW', 'FW'] as $i => $position) {
            $p = $this->player("Striker {$i}", $position, 4_000_000 + $i * 1_000);
            $this->ffRising($p);
            $this->listing($p);
        }

        $data = $this->makeMoney();

        $this->assertSame(4, $data['summary']['counts']['buy'], 'four forwards, no formation constraint');
    }

    public function test_keep_cash_when_the_market_is_empty_and_there_are_no_clauses(): void
    {
        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame('NO_OPPORTUNITIES', $data['keepCash']['reason']);
        $this->assertSame([], $data['movements']);
        $this->assertSame(0, $data['summary']['capitalToInvest']);
        $this->assertNull($data['summary']['expectedProfit']);
        $this->assertNull($data['summary']['roi']);
        $this->assertStringContainsString('no compraria res', $data['explanations'][0]);
    }

    public function test_keep_cash_when_every_candidate_is_excluded(): void
    {
        $p = $this->player('Unknown', 'FW', 10_000_000);
        $this->listing($p);

        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame('NO_ELIGIBLE_CANDIDATE', $data['keepCash']['reason']);
        $this->assertSame(['NO_MATCH' => 1], $data['excluded']['counts']);
    }

    public function test_keep_cash_when_no_candidate_beats_the_minimum_profit(): void
    {
        $p = $this->player('Tiny', 'FW', 200_000);
        $this->ffRising($p);
        $this->listing($p);

        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame('NO_PROFITABLE_CANDIDATE', $data['keepCash']['reason']);
        $this->assertSame(['BELOW_MIN_PROFIT' => 1], $data['excluded']['counts']);
    }

    public function test_keep_cash_when_the_profitable_options_are_out_of_reach(): void
    {
        $this->myTeam->update(['money' => 4_000_000]); // 1M deployable after the 3M reserve
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame('INSUFFICIENT_CASH', $data['keepCash']['reason']);
    }

    public function test_the_reserve_is_kept_back_by_default_and_can_be_turned_off(): void
    {
        $this->myTeam->update(['money' => 12_000_000]);
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $withReserve = $this->makeMoney();
        $withoutReserve = $this->makeMoney('?respect_reserve=0');

        $this->assertSame(9_000_000, $withReserve['capital']['deployableCash']);
        $this->assertSame('KEEP_CASH', $withReserve['state']);
        $this->assertSame(12_000_000, $withoutReserve['capital']['deployableCash']);
        $this->assertSame('OK', $withoutReserve['state']);
    }

    public function test_capital_is_split_into_cash_sales_and_total_and_never_blurred(): void
    {
        $this->myTeam->update(['money' => 10_000_000]);
        $mine = $this->player('Mine', 'MF', 6_000_000);
        $this->ffFalling($mine);
        $this->own($mine);

        $capital = $this->makeMoney()['capital'];

        $this->assertSame(10_000_000, $capital['currentCash']);
        $this->assertSame(7_000_000, $capital['deployableCash']);
        $this->assertSame(6_000_000, $capital['possibleCapitalFromSales']);
        $this->assertSame(13_000_000, $capital['totalDeployableCapital']);
        $this->assertTrue($capital['salesAreEstimates'], 'no real offer exists, so the sale price is an estimate');
    }

    public function test_selling_a_rising_own_player_funds_a_better_purchase_and_is_labelled_an_estimate(): void
    {
        $this->myTeam->update(['money' => 3_500_000]); // nothing deployable after the reserve
        $mine = $this->player('Mine', 'MF', 12_000_000);
        $this->ff($mine, 12_000_000, 11_990_000, 11_980_000, 11_960_000); // barely moving
        $this->own($mine);
        $target = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($target);
        $this->listing($target);

        $data = $this->makeMoney();

        $this->assertSame('OK', $data['state']);
        $this->assertSame(['SELL', 'BUY'], array_column($data['movements'], 'type'));
        $sell = $data['movements'][0];
        $this->assertSame($mine->id, $sell['playerId']);
        $this->assertSame('ESTIMATE', $sell['saleKind']);
        $this->assertStringContainsString('no és una oferta real', $sell['explanation']);
        $this->assertSame($sell['proceeds'], $data['summary']['capitalFromSales']);
        $this->assertGreaterThanOrEqual(0, $data['summary']['cashAfter']);
        $this->assertSame([], array_filter($data['holds'], fn ($h) => $h['playerId'] === $mine->id), 'a sold player is not also a hold');
    }

    public function test_a_falling_own_player_is_sold_to_avoid_the_expected_loss_and_no_purchase_is_forced(): void
    {
        $mine = $this->player('Faller', 'MF', 10_000_000);
        $this->ffFalling($mine);
        $this->own($mine);

        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state'], 'nothing worth buying');
        $this->assertSame('SELL', $data['movements'][0]['type']);
        $this->assertStringContainsString('evites una pèrdua', $data['movements'][0]['explanation']);
    }

    public function test_a_rising_own_player_is_held_with_his_evolution_shown(): void
    {
        $mine = $this->player('Riser', 'MF', 10_000_000);
        $this->ffRising($mine);
        $this->own($mine);

        $data = $this->makeMoney();

        $this->assertSame([], $data['movements']);
        $this->assertSame('HOLD', $data['holds'][0]['type']);
        $this->assertGreaterThan(0, $data['holds'][0]['gain']);
    }

    public function test_an_own_player_without_futbolfantasy_data_is_held_with_a_null_gain_not_zero(): void
    {
        $mine = $this->player('Unknown', 'MF', 10_000_000);
        $this->own($mine);

        $hold = $this->makeMoney()['holds'][0];

        $this->assertSame('HOLD', $hold['type']);
        $this->assertNull($hold['gain']);
    }

    public function test_a_clause_and_a_market_route_for_the_same_player_appear_once(): void
    {
        $p = $this->player('Both', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);
        $this->clause($p, 10_200_000);

        $data = $this->makeMoney();

        $this->assertSame([$p->id], array_column($data['movements'], 'playerId'));
    }

    public function test_a_player_listed_by_a_rival_is_never_a_market_buy_only_a_clause(): void
    {
        $p = $this->player('Rival Listed', 'FW', 10_000_000);
        $this->ffRising($p);
        $rivalEntry = $this->clause($p, 10_200_000);
        $this->listing($p)->update(['seller_team_id' => $rivalEntry->fantasy_team_id]);

        $data = $this->makeMoney();

        $this->assertSame(['CLAUSE'], array_column($data['movements'], 'type'));
        $this->assertSame(0, $data['market']['listings']);
    }

    public function test_a_clause_locked_beyond_the_horizon_is_reported_but_never_proposed(): void
    {
        $p = $this->player('Locked', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 10_500_000, ['clause_locked_until' => now()->addDays(5)]);

        $data = $this->makeMoney('?horizon=3');

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame(['CLAUSE_LOCKED' => 1], $data['excluded']['counts']);
    }

    public function test_a_clause_unlocking_within_the_horizon_is_proposed_for_its_unlock_day(): void
    {
        $p = $this->player('Unlocks soon', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 10_500_000, ['clause_locked_until' => now()->addDays(2)]);

        $short = $this->makeMoney('?horizon=3');
        $long = $this->makeMoney('?horizon=14');

        $this->assertSame([$p->id], array_column($long['movements'], 'playerId'));
        $this->assertSame('CLAUSE', $long['movements'][0]['type']);
        $this->assertSame(2, $long['movements'][0]['payableInDays']);
        $this->assertStringContainsString('es podrà pagar d\'aquí 2 dies', $long['movements'][0]['explanation']);
        // 2 days to unlock still fits a 3-day horizon too.
        $this->assertSame(2, $short['movements'][0]['payableInDays'] ?? null);
    }

    public function test_a_shielded_clause_is_never_proposed_whatever_the_horizon(): void
    {
        $p = $this->player('Shielded', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 10_500_000, ['is_locked' => true]);

        $this->assertSame('KEEP_CASH', $this->makeMoney('?horizon=14')['state']);
    }

    public function test_plans_returns_every_horizon_and_the_most_profitable_one(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $data = $this->plans();

        $this->assertSame('OK', $data['state']);
        $this->assertSame(['3', '7', '14'], array_map('strval', array_keys($data['plans'])));
        foreach ([3, 7, 14] as $h) {
            $this->assertSame($h, $data['plans'][$h]['horizon']);
            $this->assertSame($this->makeMoney('?horizon='.$h)['movements'], $data['plans'][$h]['movements']);
        }
        // A steadily rising player earns more the longer you hold him.
        $this->assertSame(14, $data['bestHorizon']);
    }

    public function test_the_horizon_filter_changes_the_figures_without_changing_the_algorithm(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $d3 = $this->makeMoney('?horizon=3');
        $d14 = $this->makeMoney('?horizon=14');

        $this->assertSame(3, $d3['horizon']);
        $this->assertSame(14, $d14['horizon']);
        $this->assertGreaterThan($d3['summary']['expectedProfit'] ?? 0, $d14['summary']['expectedProfit']);
    }

    public function test_the_min_profit_setting_is_respected(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);
        FantasySetting::create(['fantasy_account_id' => $this->account->id, 'key' => 'trading_minimum_expected_profit', 'value' => 900_000_000]);

        $this->assertSame('KEEP_CASH', $this->makeMoney()['state']);
    }

    public function test_the_distribution_of_capital_adds_up(): void
    {
        $market = $this->player('Market', 'FW', 8_000_000);
        $this->ffRising($market);
        $this->listing($market);
        $clause = $this->player('Clause', 'MF', 8_000_000);
        $this->ffRising($clause);
        $this->clause($clause, 8_500_000);

        $d = $this->makeMoney()['summary']['distribution'];

        $this->assertEqualsWithDelta(1.0, $d['marketPct'] + $d['clausePct'] + $d['cashPct'], 0.0001);
        $this->assertGreaterThan(0, $d['marketPct']);
        $this->assertGreaterThan(0, $d['clausePct']);
    }

    public function test_the_matching_summary_reaches_the_response(): void
    {
        $this->ownFullSquad();
        $this->ffRising($matched = $this->player('Matched', 'FW', 5_000_000));
        $this->listing($matched);

        $matching = $this->makeMoney()['matching'];

        $this->assertSame(1, $matching['matched']);
        $this->assertSame(15, $matching['noMatch']);
    }

    public function test_a_large_pool_is_optimized_quickly_and_deterministically(): void
    {
        $this->myTeam->update(['money' => 120_000_000]);
        $this->ownFullSquad();
        $positions = ['GK', 'DF', 'MF', 'FW'];
        for ($i = 0; $i < 150; $i++) {
            $p = $this->player("Listed {$i}", $positions[$i % 4], 3_000_000 + ($i * 137_000) % 9_000_000);
            $this->ffRising($p);
            $this->listing($p);
        }
        for ($i = 0; $i < 100; $i++) {
            $p = $this->player("Clause {$i}", $positions[$i % 4], 3_000_000 + ($i * 211_000) % 9_000_000);
            $this->ffRising($p);
            $this->clause($p, (int) ($p->market_value * 1.15));
        }

        $start = microtime(true);
        $plans = $this->plans();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(8.0, $elapsed);
        $money = $plans['plans'][14];
        $this->assertSame('OK', $money['state']);
        $this->assertSame($money['movements'], $this->makeMoney()['movements']);
        $this->assertLessThanOrEqual(120_000_000, $money['summary']['capitalToInvest']);
    }
}
