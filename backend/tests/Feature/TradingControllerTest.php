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

    private function buildXi(string $query = ''): array
    {
        return $this->api('/api/trading/build-xi'.$query)->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- common

    public function test_both_endpoints_require_authentication(): void
    {
        $this->getJson('/api/trading/make-money')->assertUnauthorized();
        $this->getJson('/api/trading/build-xi')->assertUnauthorized();
    }

    public function test_invalid_horizon_or_strategy_is_rejected(): void
    {
        $this->api('/api/trading/make-money?horizon=5')->assertStatus(422);
        $this->api('/api/trading/build-xi?horizon=30')->assertStatus(422);
        $this->api('/api/trading/build-xi?strategy=yolo')->assertStatus(422);
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
        $this->assertSame('NO_LEAGUE', $this->buildXi()['state']);
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

    public function test_locked_clauses_are_reported_but_never_proposed(): void
    {
        $p = $this->player('Locked', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 10_500_000, ['clause_locked_until' => now()->addDays(2)]);

        $data = $this->makeMoney();

        $this->assertSame('KEEP_CASH', $data['state']);
        $this->assertSame(['CLAUSE_LOCKED' => 1], $data['excluded']['counts']);
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

    // -------------------------------------------------------------- Construir onze

    public function test_build_xi_with_a_full_own_squad_costs_nothing(): void
    {
        $this->ownFullSquad();

        $data = $this->buildXi('?strategy=min_cost');

        $this->assertSame('ALREADY_HAVE_XI', $data['state']);
        $this->assertCount(11, $data['lineup']);
        $this->assertSame(0, $data['summary']['acquisitionCost']);
        $this->assertSame(['OWN'], array_unique(array_column($data['lineup'], 'origin')));
        $this->assertNull($data['summary']['newAcquisitionsRoi']);
        $this->assertTrue($data['ownCanFillXi']);
    }

    public function test_build_xi_reports_a_missing_position_as_impossible(): void
    {
        foreach (['DF' => 5, 'MF' => 5, 'FW' => 3] as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->own($this->player("{$position}{$i}", $position, 3_000_000));
            }
        }

        $data = $this->buildXi();

        $this->assertSame('IMPOSSIBLE_XI', $data['state']);
        $this->assertSame('MISSING_POSITION', $data['impossible']['reason']);
        $this->assertSame(['GK' => 1], $data['impossible']['missing']);
        $this->assertSame([], $data['lineup']);
    }

    public function test_build_xi_completes_a_missing_position_from_the_market(): void
    {
        foreach (['DF' => 5, 'MF' => 5, 'FW' => 3] as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->own($this->player("{$position}{$i}", $position, 3_000_000));
            }
        }
        $gk = $this->player('Keeper', 'GK', 6_000_000);
        $this->ffRising($gk);
        $this->listing($gk);

        $data = $this->buildXi('?strategy=min_cost');

        $this->assertSame('OK', $data['state']);
        $keeper = collect($data['lineup'])->firstWhere('playerId', $gk->id);
        $this->assertSame('MARKET', $keeper['origin']);
        $this->assertSame($keeper['cost'], $data['summary']['acquisitionCost']);
        $this->assertSame(1, $data['summary']['counts']['market']);
        $this->assertSame(10, $data['summary']['counts']['own']);
        $this->assertStringContainsString('Puja recomanada', $keeper['explanation']);
    }

    public function test_build_xi_reports_insufficient_cash_with_the_minimum_needed(): void
    {
        $this->myTeam->update(['money' => 1_000_000]);
        foreach (['DF' => 5, 'MF' => 5, 'FW' => 3] as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->own($this->player("{$position}{$i}", $position, 3_000_000));
            }
        }
        $gk = $this->player('Keeper', 'GK', 6_000_000);
        $this->ffRising($gk);
        $this->clause($gk, 7_000_000);

        $data = $this->buildXi('?strategy=min_cost');

        $this->assertSame('IMPOSSIBLE_XI', $data['state']);
        $this->assertSame('INSUFFICIENT_CASH', $data['impossible']['reason']);
        $this->assertSame(7_000_000, $data['impossible']['minimumCostToComplete']);
        $this->assertSame(6_000_000, $data['impossible']['shortfall']);
    }

    public function test_the_balanced_strategy_keeps_the_reserve_but_the_others_may_spend_it(): void
    {
        $this->myTeam->update(['money' => 8_000_000]);
        foreach (['DF' => 5, 'MF' => 5, 'FW' => 3] as $position => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->own($this->player("{$position}{$i}", $position, 3_000_000));
            }
        }
        $gk = $this->player('Keeper', 'GK', 6_000_000);
        $this->ffRising($gk);
        $this->clause($gk, 7_000_000);

        $balanced = $this->buildXi('?strategy=balanced');
        $minCost = $this->buildXi('?strategy=min_cost');

        $this->assertSame('IMPOSSIBLE_XI', $balanced['state'], '8M cash - 3M reserve = 5M < 7M clause');
        $this->assertSame(5_000_000, $balanced['capital']['budget']);
        $this->assertSame('OK', $minCost['state']);
        $this->assertFalse($minCost['summary']['respectsReserve']);
        $this->assertSame(1_000_000, $minCost['summary']['cashAfter']);
    }

    public function test_max_return_buys_a_profitable_player_over_a_free_own_one_and_splits_new_profit_from_owned_evolution(): void
    {
        foreach ($this->ownFullSquad() as $p) {
            $this->ffRising($p); // every own player has an evolution
        }
        $fw = $this->player('Star', 'FW', 8_000_000);
        $this->ffRising($fw);
        $this->listing($fw);

        $data = $this->buildXi('?strategy=max_return');

        $this->assertSame('OK', $data['state']);
        $star = collect($data['lineup'])->firstWhere('playerId', $fw->id);
        $this->assertNotNull($star);
        $this->assertSame($star['gain'], $data['summary']['newAcquisitionsProfit']);
        $this->assertGreaterThan(0, $data['summary']['ownedEvolution']);
        $this->assertNotSame($data['summary']['newAcquisitionsProfit'], $data['summary']['ownedEvolution']);
        $this->assertSame(0, collect($data['lineup'])->where('origin', 'OWN')->first()['cost']);
    }

    public function test_the_min_cost_strategy_prefers_free_own_players_over_a_profitable_purchase(): void
    {
        foreach ($this->ownFullSquad() as $p) {
            $this->ffRising($p);
        }
        $fw = $this->player('Star', 'FW', 8_000_000);
        $this->ffRising($fw);
        $this->listing($fw);

        $data = $this->buildXi('?strategy=min_cost');

        $this->assertSame('ALREADY_HAVE_XI', $data['state']);
        $this->assertNull(collect($data['lineup'])->firstWhere('playerId', $fw->id));
    }

    public function test_own_players_without_futbolfantasy_data_still_fill_the_xi_with_a_null_evolution(): void
    {
        $this->ownFullSquad();

        $data = $this->buildXi('?strategy=min_cost');

        $this->assertNull($data['summary']['ownedEvolution']);
        $this->assertSame(11, $data['summary']['ownedWithoutEvolution']);
        $this->assertNull($data['lineup'][0]['gain']);
    }

    public function test_the_xi_uses_a_configured_formation_and_each_player_once(): void
    {
        $this->ownFullSquad();

        $data = $this->buildXi();

        $this->assertContains($data['formation'], array_keys(config('fantasy.trading.formations')));
        $ids = array_column($data['lineup'], 'playerId');
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertContains(array_count_values(array_column($data['lineup'], 'position')), array_values(config('fantasy.trading.formations')));
    }

    public function test_build_xi_lists_excluded_candidates_with_their_reason(): void
    {
        $this->ownFullSquad();
        $unknown = $this->player('Unknown', 'FW', 5_000_000);
        $this->listing($unknown);

        $data = $this->buildXi();

        $this->assertSame(['NO_MATCH' => 1], $data['excluded']['counts']);
        $this->assertSame($unknown->id, $data['excluded']['items'][0]['playerId']);
    }

    public function test_the_matching_summary_reaches_the_response(): void
    {
        $this->ownFullSquad();
        $this->ffRising($matched = $this->player('Matched', 'FW', 5_000_000));
        $this->listing($matched);

        $matching = $this->buildXi()['matching'];

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
        $money = $this->makeMoney();
        $xi = $this->buildXi('?strategy=max_return');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(8.0, $elapsed);
        $this->assertSame('OK', $money['state']);
        $this->assertSame('OK', $xi['state']);
        $this->assertSame($money['movements'], $this->makeMoney()['movements']);
        $this->assertLessThanOrEqual(120_000_000, $money['summary']['capitalToInvest']);
        $this->assertLessThanOrEqual(120_000_000, $xi['summary']['acquisitionCost']);
    }
}
