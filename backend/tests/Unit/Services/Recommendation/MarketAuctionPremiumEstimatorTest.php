<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\User;
use App\Services\Recommendation\MarketAuctionPremiumEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarketAuctionPremiumEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function league(): FantasyLeague
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);

        return FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
    }

    private function player(int $marketValue): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => 'Player '.uniqid(),
            'position' => 'MF',
            'market_value' => $marketValue,
            'average_points' => 5.0,
            'points' => 50,
            'status' => 'ok',
        ]);
    }

    private function acceptedBid(FantasyLeague $league, FantasyPlayer $player, int $amount, Carbon $resolvedAt): FantasyOffer
    {
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'Buyer']);

        $offer = FantasyOffer::create([
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'offering_team_id' => $team->id,
            'amount' => $amount,
            'status' => FantasyOffer::STATUS_ACCEPTED,
            'type' => 'MARKET_BID',
        ]);
        $offer->forceFill(['updated_at' => $resolvedAt])->save();

        return $offer;
    }

    /** 12. With enough league history, the real median premium is used instead of the fallback. */
    public function test_uses_real_league_history_once_there_are_enough_samples(): void
    {
        $league = $this->league();

        // Five accepted bids, each at a real, verifiable +10% premium over
        // the player's snapshotted market value at the moment the bid resolved.
        foreach ([8_000_000, 9_000_000, 10_000_000, 11_000_000, 12_000_000] as $marketValue) {
            $player = $this->player($marketValue);
            $player->snapshots()->create(['market_value' => $marketValue, 'captured_at' => Carbon::now()->subDays(2)]);
            $this->acceptedBid($league, $player, (int) round($marketValue * 1.10), Carbon::now()->subDay());
        }

        $estimator = new MarketAuctionPremiumEstimator(minSamples: 5);
        $result = $estimator->estimate($league);

        $this->assertSame('league', $result['source']);
        $this->assertSame(5, $result['sampleCount']);
        $this->assertEqualsWithDelta(0.10, $result['premium'], 0.001);
    }

    /** 13. Too little history falls back to the configured flat premium rather than trusting a thin sample. */
    public function test_falls_back_to_the_configured_premium_when_history_is_too_thin(): void
    {
        $league = $this->league();

        $player = $this->player(10_000_000);
        $player->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(2)]);
        $this->acceptedBid($league, $player, 11_000_000, Carbon::now()->subDay());

        $estimator = new MarketAuctionPremiumEstimator(minSamples: 5, fallbackPremium: 0.07);
        $result = $estimator->estimate($league);

        $this->assertSame('fallback', $result['source']);
        $this->assertSame(1, $result['sampleCount']);
        $this->assertSame(0.07, $result['premium']);
    }

    public function test_uses_the_median_not_the_mean_so_one_outlier_does_not_skew_the_estimate(): void
    {
        $league = $this->league();

        // Four bids at a real +10% premium, one wild outlier at +200%.
        foreach ([10_000_000, 10_000_000, 10_000_000, 10_000_000] as $marketValue) {
            $player = $this->player($marketValue);
            $player->snapshots()->create(['market_value' => $marketValue, 'captured_at' => Carbon::now()->subDays(2)]);
            $this->acceptedBid($league, $player, (int) round($marketValue * 1.10), Carbon::now()->subDay());
        }
        $outlier = $this->player(10_000_000);
        $outlier->snapshots()->create(['market_value' => 10_000_000, 'captured_at' => Carbon::now()->subDays(2)]);
        $this->acceptedBid($league, $outlier, 30_000_000, Carbon::now()->subDay());

        $estimator = new MarketAuctionPremiumEstimator(minSamples: 5);
        $result = $estimator->estimate($league);

        $this->assertSame('league', $result['source']);
        $this->assertEqualsWithDelta(0.10, $result['premium'], 0.001);
    }
}
