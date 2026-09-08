<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use App\Services\ExternalData\SparklineHistoryBuilder;
use App\Services\Recommendation\ClauseEconomicAnalysisService;
use App\Services\Recommendation\ClauseOpportunityService;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\MarketValueProjector;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClauseOpportunityServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ClauseOpportunityService
    {
        $scoreService = new FantasyScoreService(new TrendAnalysisService, new FantasySettingsService);

        return new ClauseOpportunityService(
            new FantasySettingsService,
            new PlayerTrendPresenter(new TrendAnalysisService, $scoreService, new SparklineHistoryBuilder),
            new ClauseEconomicAnalysisService(new MarketValueProjector),
        );
    }

    /** @return array{account: FantasyAccount, league: FantasyLeague, myTeam: FantasyTeam} */
    private function setupLeague(int $cash = 50_000_000): array
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $myTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $account->update(['active_league_id' => $league->id, 'active_team_id' => $myTeam->id]);

        return ['account' => $account->fresh(), 'league' => $league, 'myTeam' => $myTeam];
    }

    private function player(string $name, string $position, int $marketValue): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => $name,
            'position' => $position,
            'market_value' => $marketValue,
            'average_points' => 5.0,
            'points' => 50,
            'status' => 'ok',
        ]);
    }

    private function rivalTeamPlayer(FantasyLeague $league, FantasyPlayer $player, int $clauseValue, array $extra = []): FantasyTeamPlayer
    {
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Rival', 'is_mine' => false]);

        return FantasyTeamPlayer::create(array_merge([
            'fantasy_team_id' => $rivalTeam->id,
            'fantasy_player_id' => $player->id,
            'clause_value' => $clauseValue,
        ], $extra));
    }

    public function test_returns_the_full_economic_analysis_alongside_roster_context(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $rivalPlayer = $this->player('Rising FW', 'FW', 10_000_000);
        foreach ([7 => 9_000_000, 3 => 9_600_000, 1 => 9_900_000, 0 => 10_000_000] as $daysAgo => $value) {
            $rivalPlayer->snapshots()->create(['market_value' => $value, 'captured_at' => Carbon::now()->subDays($daysAgo)]);
        }
        $this->rivalTeamPlayer($league, $rivalPlayer, 9_500_000); // clause below market value

        $row = $this->service()->evaluate($account)->first();

        // Economic engine output, straight from ClauseEconomicAnalysis::toArray().
        $this->assertSame(9_500_000.0, $row['clauseValue']);
        $this->assertSame(-500_000.0, $row['clausePremium']);
        $this->assertLessThan(0, $row['clausePremiumPct']);
        $this->assertSame(0, $row['breakEvenDays']); // clause already at/below market value
        $this->assertContains($row['economicRecommendation'], ['PAY_CLAUSE', 'CONSIDER', 'WAIT', 'DO_NOT_PAY']);
        $this->assertIsInt($row['clauseEconomicScore']);

        // Roster/context fields the economic engine doesn't know about.
        $this->assertSame('Rising FW', $row['playerName']);
        $this->assertFalse($row['isLocked']);
        $this->assertIsFloat($row['fantasyScore']);
        $this->assertNotEmpty($row['history']);
    }

    public function test_prefers_our_own_snapshots_over_external_data_per_window(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $rivalPlayer = $this->player('Own Data MF', 'FW', 10_000_000);
        // Only a real 1-day-old snapshot of our own; 3d/7d must fall back to external.
        $rivalPlayer->snapshots()->create(['market_value' => 9_900_000, 'captured_at' => Carbon::now()->subDay()]);
        FantasyExternalTrend::create([
            'fantasy_player_id' => $rivalPlayer->id,
            'source' => 'futbolfantasy',
            'match_confidence' => 'exact',
            'value_3d' => 9_500_000,
            'value_7d' => 9_000_000,
            'fetched_at' => now(),
        ]);
        $this->rivalTeamPlayer($league, $rivalPlayer, 10_000_000);

        $row = $this->service()->evaluate($account)->first();

        // (10M / 9.9M) - 1, from our own snapshot.
        $this->assertEqualsWithDelta(0.0101, $row['growth1d'], 0.001);
        // (10M / 9.5M)^(1/3) - 1, from the external value_3d fallback.
        $this->assertEqualsWithDelta(0.0173, $row['growth3d'], 0.001);
    }

    public function test_ignores_an_implausible_external_value_from_a_likely_mismatched_player(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        // Real case: futbolfantasy.com matched this player to someone else
        // entirely, so value_1d/3d/7d are ~12x off our real market value —
        // using them would have compounded into a nonsensical ROI.
        $rivalPlayer = $this->player('Mismatched DF', 'DF', 8_717_731);
        FantasyExternalTrend::create([
            'fantasy_player_id' => $rivalPlayer->id,
            'source' => 'futbolfantasy',
            'match_confidence' => 'exact',
            'value_1d' => 719_485,
            'value_3d' => 741_580,
            'value_7d' => 805_228,
            'fetched_at' => now(),
        ]);
        $this->rivalTeamPlayer($league, $rivalPlayer, 8_717_731);

        $row = $this->service()->evaluate($account)->first();

        $this->assertNull($row['growth1d']);
        $this->assertNull($row['growth3d']);
        $this->assertNull($row['growth7d']);
        $this->assertSame(0.0, $row['expectedDailyGrowth']);
        // A valid 0-100 score, not the astronomical figure the bad data would have produced.
        $this->assertGreaterThanOrEqual(0, $row['clauseEconomicScore']);
        $this->assertLessThanOrEqual(100, $row['clauseEconomicScore']);
    }

    public function test_a_locked_clause_can_still_be_recommended_and_reports_days_remaining(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $rivalPlayer = $this->player('Locked GK', 'GK', 5_000_000);
        FantasyExternalTrend::create([
            'fantasy_player_id' => $rivalPlayer->id,
            'source' => 'futbolfantasy',
            'match_confidence' => 'exact',
            'value_1d' => 4_950_000,
            'value_3d' => 4_850_000,
            'value_7d' => 4_650_000,
            'fetched_at' => now(),
        ]);
        $this->rivalTeamPlayer($league, $rivalPlayer, 5_100_000, ['clause_locked_until' => Carbon::now()->addDays(5)]);

        $row = $this->service()->evaluate($account)->first();

        $this->assertTrue($row['isLocked']);
        $this->assertSame(5, $row['daysUntilUnlock']);
        // Locked status must not have been folded into the economic verdict.
        $this->assertContains($row['economicRecommendation'], ['PAY_CLAUSE', 'CONSIDER', 'WAIT', 'DO_NOT_PAY']);
    }

    public function test_an_unlocked_clause_reports_no_days_remaining(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $rivalPlayer = $this->player('Free To Buy MF', 'MF', 5_000_000);
        $this->rivalTeamPlayer($league, $rivalPlayer, 5_200_000, ['clause_locked_until' => Carbon::now()->subDays(2)]);

        $row = $this->service()->evaluate($account)->first();

        $this->assertFalse($row['isLocked']);
        $this->assertNull($row['daysUntilUnlock']);
    }

    public function test_affordability_is_informational_only(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague(cash: 1_000_000);

        $rivalPlayer = $this->player('Expensive DF', 'DF', 10_000_000);
        $this->rivalTeamPlayer($league, $rivalPlayer, 9_000_000); // discount clause, should still score well

        $row = $this->service()->evaluate($account)->first();

        $this->assertFalse($row['affordable']);
        $this->assertLessThanOrEqual(0, $row['clausePremium']);
    }

    public function test_with_no_historical_data_at_all_a_discount_clause_still_scores_better_than_a_premium_one(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $cheap = $this->player('Below Market MF', 'MF', 10_000_000);
        $this->rivalTeamPlayer($league, $cheap, 9_500_000); // -5% premium, no trend data -> assumed 0% growth

        $expensive = $this->player('Above Market MF', 'MF', 10_000_000);
        $this->rivalTeamPlayer($league, $expensive, 10_500_000); // +5% premium, no trend data -> assumed 0% growth

        $rows = $this->service()->evaluate($account)->keyBy('playerName');

        $this->assertGreaterThan($rows['Above Market MF']['clauseEconomicScore'], $rows['Below Market MF']['clauseEconomicScore']);
    }

    public function test_results_are_sorted_by_clause_economic_score_descending(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $great = $this->player('Great Deal MF', 'MF', 10_000_000);
        $this->rivalTeamPlayer($league, $great, 8_000_000); // clause well below market value

        $bad = $this->player('Bad Deal MF', 'MF', 10_000_000);
        $this->rivalTeamPlayer($league, $bad, 20_000_000); // clause far above market value

        $rows = $this->service()->evaluate($account)->values();

        $this->assertSame('Great Deal MF', $rows->first()['playerName']);
        $this->assertGreaterThanOrEqual($rows->last()['clauseEconomicScore'], $rows->first()['clauseEconomicScore']);
    }

    public function test_includes_a_7_day_value_history_for_the_sparkline(): void
    {
        ['account' => $account, 'league' => $league] = $this->setupLeague();

        $rivalPlayer = $this->player('Trending FW', 'FW', 8_000_000);
        foreach ([7 => 7_000_000, 3 => 7_500_000, 1 => 7_800_000, 0 => 8_000_000] as $daysAgo => $value) {
            $rivalPlayer->snapshots()->create(['market_value' => $value, 'captured_at' => Carbon::now()->subDays($daysAgo)]);
        }
        $this->rivalTeamPlayer($league, $rivalPlayer, 8_300_000);

        $row = $this->service()->evaluate($account)->first();

        $this->assertNotEmpty($row['history']);
        $this->assertSame('own', $row['historySource']);
        $this->assertEqualsWithDelta(14.3, $row['trend']['pctChange7d'], 0.1);
    }
}
