<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyMarketPlayer;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\TrendAnalysisService;
use Illuminate\Http\Request;

class TradingController extends Controller
{
    /**
     * Naive linear extrapolation of the current daily value trend over the
     * configured trading horizon. This is intentionally simple (no ML, no
     * hidden model) so the number is always traceable back to real
     * fantasy_player_snapshots — a wrong-but-honest projection beats a
     * black-box one here.
     */
    public function opportunities(Request $request, TrendAnalysisService $trendService, FantasySettingsService $settings)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;

        if (! $league) {
            return response()->json(['data' => [], 'message' => 'No active league selected yet.']);
        }

        $rules = $settings->rules($account);
        $horizonDays = (int) $rules['trading_horizon_days'];
        $minProfit = (int) $rules['trading_minimum_expected_profit'];

        $listings = FantasyMarketPlayer::query()
            ->where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->with('player')
            ->get();

        $opportunities = $listings
            ->filter(fn ($listing) => $listing->player && $listing->market_value)
            ->map(function (FantasyMarketPlayer $listing) use ($trendService, $horizonDays) {
                $trend = $trendService->analyze($listing->player);

                if (! $trend->hasEnoughData() || $trend->change24h === null || $trend->change24h <= 0) {
                    return null;
                }

                $buyPrice = (int) round($listing->market_value * 1.03);
                $projectedValue = $listing->market_value + ($trend->change24h * $horizonDays);
                $estimatedProfit = $projectedValue - $buyPrice;

                return [
                    'player' => [
                        'id' => $listing->player->id,
                        'name' => $listing->player->name,
                        'position' => $listing->player->position,
                    ],
                    'marketValue' => $listing->market_value,
                    'buyPrice' => $buyPrice,
                    'dailyTrend' => $trend->change24h,
                    'horizonDays' => $horizonDays,
                    'projectedValue' => (int) round($projectedValue),
                    'estimatedProfit' => (int) round($estimatedProfit),
                ];
            })
            ->filter(fn ($o) => $o && $o['estimatedProfit'] >= $minProfit)
            ->sortByDesc('estimatedProfit')
            ->values();

        return response()->json(['data' => $opportunities]);
    }
}
