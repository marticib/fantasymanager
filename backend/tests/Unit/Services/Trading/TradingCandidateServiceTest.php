<?php

namespace Tests\Unit\Services\Trading;

use App\Models\FantasyOffer;
use App\Models\FantasyPlayerSnapshot;
use App\Services\Trading\TradingCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTradingFixtures;
use Tests\TestCase;

class TradingCandidateServiceTest extends TestCase
{
    use BuildsTradingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTrading();
    }

    /** @return array<string, mixed> */
    private function pool(): array
    {
        return app(TradingCandidateService::class)->pool($this->account->fresh());
    }

    private function marketRow(int $playerId): array
    {
        return collect($this->pool()['market'])->firstWhere('playerId', $playerId);
    }

    public function test_a_market_buy_costs_the_recommended_bid_which_never_exceeds_max_bid(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertTrue($row['eligible']);
        $this->assertSame($row['recommendedBid'], $row['acquisitionCost']);
        $this->assertLessThanOrEqual($row['maxBid'], $row['acquisitionCost']);
        $this->assertLessThanOrEqual($row['engineMaxBid'], $row['acquisitionCost']);
    }

    public function test_profit_is_projected_value_minus_acquisition_cost_and_roi_is_profit_over_cost(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        foreach ([3, 7, 14] as $h) {
            $this->assertEqualsWithDelta($row['projected'][$h] - $row['acquisitionCost'], $row['profit'][$h], 1);
            $this->assertEqualsWithDelta($row['profit'][$h] / $row['acquisitionCost'], $row['roi'][$h], 0.0001);
        }
        $this->assertGreaterThan($row['profit'][3], $row['profit'][14], 'a rising player earns more over a longer horizon');
    }

    public function test_the_economic_value_and_history_come_from_futbolfantasy_not_our_own_snapshots(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p, ['value_now' => 10_100_000]);
        // Our own history says the opposite (falling) — it must be ignored.
        foreach ([7 => 12_000_000, 3 => 11_000_000, 1 => 10_500_000] as $daysAgo => $value) {
            FantasyPlayerSnapshot::create(['fantasy_player_id' => $p->id, 'market_value' => $value, 'captured_at' => now()->subDays($daysAgo)]);
        }
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertSame(10_100_000, $row['economicValue']);
        $this->assertSame(10_000_000, $row['marketValue']);
        $this->assertGreaterThan(0, $row['growth']['expectedDailyGrowth']);
    }

    public function test_the_estimated_winning_bid_is_measured_over_laligas_value_not_futbolfantasys(): void
    {
        $p = $this->player('Riser', 'FW', 10_000_000);
        $this->ffRising($p, ['value_now' => 10_500_000]);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertSame(10_500_000, $row['estimatedWinningBid'], '10M LaLiga value x 1.05 fallback premium, not 10.5M x 1.05');
    }

    public function test_a_player_with_no_futbolfantasy_match_is_excluded_and_flagged_never_invented(): void
    {
        $p = $this->player('Unknown', 'FW', 10_000_000);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertFalse($row['eligible']);
        $this->assertSame('NO_MATCH', $row['excludeReason']);
        $this->assertSame('NO_MATCH', $row['external']['matchStatus']);
        $this->assertNull($row['profit']);
        $this->assertNull($row['projected']);
        $this->assertSame(1, $this->pool()['matching']['noMatch']);
    }

    public function test_a_futbolfantasy_row_with_no_usable_window_is_insufficient_data(): void
    {
        $p = $this->player('Thin', 'FW', 10_000_000);
        $this->ff($p, 10_000_000, null, null, null);
        $this->listing($p);

        $this->assertSame('INSUFFICIENT_FF_DATA', $this->marketRow($p->id)['excludeReason']);
    }

    public function test_a_missing_window_stays_null_and_is_flagged_never_turned_into_zero(): void
    {
        $p = $this->player('Partial', 'FW', 10_000_000);
        $this->ff($p, 10_000_000, 9_700_000, null, null);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertNotNull($row['growth']['growth1d']);
        $this->assertNull($row['growth']['growth3d']);
        $this->assertNull($row['growth']['growth7d']);
        $this->assertContains('PARTIAL_HISTORY', $row['warnings']);
        $this->assertSame(33, $row['confidence']['components']['windows']);
    }

    public function test_a_wildly_different_futbolfantasy_value_is_a_suspect_match_and_excluded(): void
    {
        $p = $this->player('Suspect', 'FW', 10_000_000);
        $this->ffRising($p, ['value_now' => 3_000_000, 'value_1d' => 2_900_000, 'value_3d' => 2_800_000, 'value_7d' => 2_600_000]);
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertSame('SUSPECT_VALUE_MISMATCH', $row['excludeReason']);
        $this->assertSame(1, $this->pool()['matching']['suspectValue']);
    }

    public function test_a_moderate_value_divergence_only_warns_and_lowers_confidence(): void
    {
        $exact = $this->player('Exact', 'FW', 10_000_000);
        $this->ffRising($exact);
        $this->listing($exact);
        $off = $this->player('Off', 'FW', 10_000_000);
        $this->ffRising($off, ['value_now' => 11_500_000, 'value_1d' => 11_150_000, 'value_3d' => 10_600_000, 'value_7d' => 9_800_000]);
        $this->listing($off);

        $rows = collect($this->pool()['market']);

        $this->assertContains('VALUE_DIVERGENCE', $rows->firstWhere('playerId', $off->id)['warnings']);
        $this->assertLessThan(
            $rows->firstWhere('playerId', $exact->id)['confidence']['components']['value_consistency'],
            $rows->firstWhere('playerId', $off->id)['confidence']['components']['value_consistency'],
        );
    }

    public function test_do_not_chase_when_the_estimated_winning_bid_exceeds_max_bid(): void
    {
        $p = $this->player('Flat', 'FW', 10_000_000);
        $this->ff($p, 10_000_000, 10_000_000, 10_000_000, 10_000_000); // zero growth → MaxBid ≈ 9.5M < 10.5M
        $this->listing($p);

        $row = $this->marketRow($p->id);

        $this->assertFalse($row['eligible']);
        $this->assertSame('DO_NOT_CHASE', $row['excludeReason']);
        $this->assertSame('DO_NOT_CHASE', $row['recommendedBid']);
        $this->assertNull($row['acquisitionCost']);
    }

    public function test_the_bid_count_never_raises_max_bid(): void
    {
        $quiet = $this->player('Quiet', 'FW', 10_000_000);
        $this->ffRising($quiet);
        $this->listing($quiet, null, null, ['numberOfOffers' => 0]);
        $busy = $this->player('Busy', 'FW', 10_000_000);
        $this->ffRising($busy);
        $this->listing($busy, null, null, ['numberOfOffers' => 25]);

        $rows = collect($this->pool()['market']);

        $this->assertSame($rows->firstWhere('playerId', $quiet->id)['maxBid'], $rows->firstWhere('playerId', $busy->id)['maxBid']);
        $this->assertSame($rows->firstWhere('playerId', $quiet->id)['recommendedBid'], $rows->firstWhere('playerId', $busy->id)['recommendedBid']);
    }

    public function test_a_listing_asking_more_than_max_bid_is_excluded_as_max_bid_too_low(): void
    {
        $p = $this->player('Pricey', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p, 10_000_000, 12_500_000); // asking 25% over market; rule cap is +8%

        $row = $this->marketRow($p->id);

        $this->assertFalse($row['eligible']);
        $this->assertSame('MAX_BID_TOO_LOW', $row['excludeReason']);
        $this->assertNull($row['acquisitionCost']);
        $this->assertSame(10_800_000, $row['maxBid'], 'min(engine MaxBid, +8% rule cap)');
    }

    public function test_a_clause_costs_the_clause_value_and_profit_is_projection_minus_clause(): void
    {
        $p = $this->player('Target', 'MF', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 12_000_000);

        $row = $this->pool()['clause'][0];

        $this->assertTrue($row['eligible']);
        $this->assertSame(12_000_000, $row['acquisitionCost']);
        $this->assertEqualsWithDelta($row['projected'][14] - 12_000_000, $row['profit'][14], 1);
    }

    public function test_a_locked_or_shielded_clause_is_listed_but_never_eligible(): void
    {
        $locked = $this->player('Locked', 'MF', 10_000_000);
        $this->ffRising($locked);
        $this->clause($locked, 12_000_000, ['clause_locked_until' => now()->addDays(3)]);
        $shielded = $this->player('Shielded', 'MF', 10_000_000);
        $this->ffRising($shielded);
        $this->clause($shielded, 12_000_000, ['is_locked' => true]);

        $rows = collect($this->pool()['clause']);

        $lockedRow = $rows->firstWhere('playerId', $locked->id);
        $this->assertSame('CLAUSE_LOCKED', $lockedRow['excludeReason']);
        $this->assertFalse($lockedRow['eligible']);
        $this->assertGreaterThanOrEqual(3, $lockedRow['daysUntilUnlock']);
        $this->assertSame('CLAUSE_SHIELDED', $rows->firstWhere('playerId', $shielded->id)['excludeReason']);
    }

    public function test_a_player_on_the_market_and_holding_a_clause_yields_both_routes(): void
    {
        $p = $this->player('Both', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->listing($p);
        $this->clause($p, 11_000_000);

        $pool = $this->pool();

        $this->assertSame([$p->id], array_column($pool['market'], 'playerId'));
        $this->assertSame([$p->id], array_column($pool['clause'], 'playerId'));
    }

    public function test_my_own_players_are_never_offered_as_market_or_clause_acquisitions(): void
    {
        $p = $this->player('Mine', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->own($p);
        $this->listing($p);

        $pool = $this->pool();

        $this->assertSame([], $pool['market']);
        $this->assertCount(1, $pool['own']);
    }

    public function test_an_own_player_costs_nothing_and_exposes_his_expected_evolution(): void
    {
        $p = $this->player('Mine', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->own($p);

        $row = $this->pool()['own'][0];

        $this->assertSame(0, $row['acquisitionCost']);
        $this->assertGreaterThan(0, $row['evolution'][14]);
        $this->assertEqualsWithDelta($row['projected'][14] - 10_000_000, $row['evolution'][14], 1);
    }

    public function test_an_own_player_without_futbolfantasy_data_stays_in_the_pool_with_a_null_evolution(): void
    {
        $p = $this->player('Mine', 'GK', 5_000_000);
        $this->own($p);

        $row = $this->pool()['own'][0];

        $this->assertNull($row['evolution']);
        $this->assertNull($row['projected']);
        $this->assertNotNull($row['salePrice']);
    }

    public function test_a_sale_estimate_is_labelled_and_a_real_pending_offer_is_used_when_there_is_one(): void
    {
        $estimated = $this->player('NoOffer', 'FW', 10_000_000);
        $this->ffRising($estimated);
        $this->own($estimated);
        $offered = $this->player('Offer', 'FW', 10_000_000);
        $this->ffRising($offered);
        $this->own($offered);
        FantasyOffer::create([
            'fantasy_league_id' => $this->league->id,
            'fantasy_player_id' => $offered->id,
            'receiving_team_id' => $this->myTeam->id,
            'amount' => 11_200_000,
            'status' => FantasyOffer::STATUS_PENDING,
            'type' => 'offer',
        ]);

        $rows = collect($this->pool()['own']);

        $this->assertSame('ESTIMATE', $rows->firstWhere('playerId', $estimated->id)['saleKind']);
        $this->assertLessThan($rows->firstWhere('playerId', $offered->id)['saleCertainty'], $rows->firstWhere('playerId', $estimated->id)['saleCertainty']);
        $this->assertSame('OFFER', $rows->firstWhere('playerId', $offered->id)['saleKind']);
        $this->assertSame(11_200_000, $rows->firstWhere('playerId', $offered->id)['salePrice']);
    }

    public function test_confidence_is_computed_from_real_data_quality_and_drops_when_it_worsens(): void
    {
        $good = $this->player('Good', 'FW', 10_000_000);
        $this->ffRising($good);
        $this->clause($good, 11_000_000);
        $stale = $this->player('Stale', 'FW', 10_000_000);
        $this->ffRising($stale, ['fetched_at' => now()->subDays(2), 'match_confidence' => 'suffix']);
        $this->clause($stale, 11_000_000);

        $rows = collect($this->pool()['clause']);
        $goodRow = $rows->firstWhere('playerId', $good->id);
        $staleRow = $rows->firstWhere('playerId', $stale->id);

        $this->assertGreaterThan($staleRow['confidence']['score'], $goodRow['confidence']['score']);
        $this->assertSame(100, $goodRow['confidence']['components']['freshness']);
        $this->assertSame(0, $staleRow['confidence']['components']['freshness']);
        $this->assertSame(70, $staleRow['confidence']['components']['match']);
        $this->assertSame('HIGH', $goodRow['confidence']['level']);
    }

    public function test_a_candidate_below_the_minimum_confidence_is_not_recommended(): void
    {
        config(['fantasy.trading.min_confidence_to_recommend' => 101]);
        $p = $this->player('Meh', 'FW', 10_000_000);
        $this->ffRising($p);
        $this->clause($p, 11_000_000);

        $row = $this->pool()['clause'][0];

        $this->assertFalse($row['eligible']);
        $this->assertSame('LOW_CONFIDENCE', $row['excludeReason']);
    }

    public function test_the_view_collapses_the_horizon_and_marks_own_gain_as_evolution(): void
    {
        $own = $this->player('Mine', 'FW', 10_000_000);
        $this->ffRising($own);
        $this->own($own);
        $service = app(TradingCandidateService::class);

        $view = $service->view($this->pool()['own'][0], 7);

        $this->assertSame($this->pool()['own'][0]['evolution'][7], $view['gain']);
        $this->assertNull($view['roi']);
        $this->assertSame(0, $view['cost']);
    }

    public function test_an_empty_market_and_no_clauses_yield_empty_lists(): void
    {
        $pool = $this->pool();

        $this->assertSame([], $pool['market']);
        $this->assertSame([], $pool['clause']);
        $this->assertSame(0, $pool['matching']['total']);
    }
}
