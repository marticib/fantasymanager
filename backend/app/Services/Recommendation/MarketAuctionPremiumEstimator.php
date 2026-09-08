<?php

namespace App\Services\Recommendation;

use App\Models\FantasyLeague;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use Illuminate\Support\Carbon;

/**
 * Estimates the premium over market value a winning market bid is likely to
 * need in this league, from this league's own history of accepted market
 * bids (fantasy_offers, type=MARKET_BID/status=ACCEPTED) — preferring the
 * median so one outlier panic-bid doesn't skew every MaxBid/RecommendedBid
 * in the league. fantasy_offers isn't populated by any sync yet (see
 * PlayerDecisionEngine's Trade Score `currentOffer()` for the same honest
 * gap), so in practice this falls back to the configured flat premium today
 * — never an invented "typical" figure dressed up as real data — and starts
 * using real history automatically once a sync populates it.
 */
class MarketAuctionPremiumEstimator
{
    private readonly int $minSamples;

    private readonly float $fallbackPremium;

    public function __construct(?int $minSamples = null, ?float $fallbackPremium = null)
    {
        $this->minSamples = $minSamples ?? config('fantasy.market_buy_analysis.min_auction_history_samples');
        $this->fallbackPremium = $fallbackPremium ?? config('fantasy.market_buy_analysis.fallback_winning_premium_pct');
    }

    /**
     * @return array{premium: float, source: 'league'|'fallback', sampleCount: int}
     */
    public function estimate(FantasyLeague $league): array
    {
        $premiums = $this->historicalPremiums($league);

        if (count($premiums) < $this->minSamples) {
            return ['premium' => $this->fallbackPremium, 'source' => 'fallback', 'sampleCount' => count($premiums)];
        }

        return ['premium' => $this->median($premiums), 'source' => 'league', 'sampleCount' => count($premiums)];
    }

    /**
     * One premium sample per accepted market bid: (amount - marketValueAtAuction)
     * / marketValueAtAuction, where marketValueAtAuction is the player's own
     * fantasy_player_snapshots value closest to (at or before) the moment the
     * offer was resolved — the same "closest snapshot before a target time"
     * pattern used throughout the app (TrendAnalysisService, PlayerValueTrendCalculator),
     * just applied to an offer's resolution time instead of "now".
     *
     * @return array<int, float>
     */
    private function historicalPremiums(FantasyLeague $league): array
    {
        $offers = FantasyOffer::query()
            ->where('fantasy_league_id', $league->id)
            ->where('type', 'MARKET_BID')
            ->where('status', FantasyOffer::STATUS_ACCEPTED)
            ->with('player')
            ->get();

        $premiums = [];

        foreach ($offers as $offer) {
            $marketValueAtAuction = $this->marketValueAt($offer->player, $offer->updated_at);

            if ($marketValueAtAuction === null || $marketValueAtAuction <= 0) {
                continue;
            }

            $premiums[] = ($offer->amount - $marketValueAtAuction) / $marketValueAtAuction;
        }

        return $premiums;
    }

    private function marketValueAt(?FantasyPlayer $player, Carbon $at): ?float
    {
        if (! $player) {
            return null;
        }

        $snapshot = $player->snapshots()
            ->whereNotNull('market_value')
            ->where('captured_at', '<=', $at)
            ->orderByDesc('captured_at')
            ->first(['market_value']);

        return $snapshot?->market_value !== null ? (float) $snapshot->market_value : null;
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }
}
