<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyRecommendationResource;
use App\Models\FantasyClub;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyRecommendation;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\MarketAuctionPremiumEstimator;
use App\Services\Recommendation\MarketBuyAnalysisService;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\PlayerValueTrendCalculator;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    public function index(
        Request $request,
        FantasySettingsService $settings,
        PlayerTrendPresenter $presenter,
        PlayerValueTrendCalculator $trendCalculator,
        MarketBuyAnalysisService $buyAnalysisService,
        MarketAuctionPremiumEstimator $premiumEstimator,
    ) {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;

        if (! $league) {
            return response()->json(['message' => 'No active league selected yet.'], 404);
        }

        $rules = $settings->rules($account);
        $team = $account->activeTeam;
        $availableCapital = max(0, (int) ($team->money ?? 0) - (int) $rules['minimum_cash_reserve']);
        $clubShortNames = FantasyClub::pluck('short_name', 'external_id')->all();
        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')->get()->keyBy('fantasy_player_id');

        // League-wide, not per-listing: the "typical winning premium" this
        // league's own auction history implies, shared by every row's Buy
        // Economic Score below — see MarketAuctionPremiumEstimator.
        $premium = $premiumEstimator->estimate($league);

        $base = FantasyMarketPlayer::query()->where('fantasy_league_id', $league->id)->where('is_on_market', true);
        $totalAvailable = (clone $base)->count();
        $closesAt = (clone $base)->whereNotNull('expires_at')->min('expires_at');

        $query = (clone $base)->with('player', 'sellerTeam');

        if ($position = $request->query('position')) {
            $query->whereHas('player', fn ($q) => $q->where('position', $position));
        }
        if ($maxPrice = $request->query('max_price')) {
            $query->where('market_value', '<=', (int) $maxPrice);
        }
        if ($search = $request->query('search')) {
            $query->whereHas('player', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
        }

        $rows = $query->get()
            ->map(function (FantasyMarketPlayer $listing) use (
                $account, $rules, $availableCapital, $clubShortNames, $externalTrends, $presenter,
                $trendCalculator, $buyAnalysisService, $premium,
            ) {
                if (! $listing->player) {
                    return null;
                }

                $marketValue = $listing->market_value ?? 0;
                $externalTrend = $externalTrends->get($listing->player->id);
                $p = $presenter->present($listing->player, $account, $externalTrend, $marketValue);

                $maxBid = (int) min(
                    round($marketValue * (1 + ($rules['maximum_bid_over_market_percentage'] / 100))),
                    $availableCapital,
                );

                // Never fed into FantasyRecommendationEngine or fantasy_recommendations
                // (the dashboard's official recommendations) — see PlayerTrendPresenter
                // and TrendAnalysisService::effectiveClassification.
                $buySignal = $p->score->total >= 65 && $p->isRising();
                $sellSignal = $p->isFalling();

                // Purely economic verdict — never sporting, never squad fit —
                // at the listing's own asking price (LaLiga's reference price
                // for this auction, not fabricated). See buyAnalysis() for the
                // "what if I bid a different price" recompute.
                $acquisitionPrice = (float) ($listing->asking_price ?? $marketValue);
                [$value1d, $value3d, $value7d] = $trendCalculator->historicalValues($listing->player, (float) $marketValue, $externalTrend);
                $bidCount = $listing->raw_payload['numberOfOffers'] ?? null;

                $buyAnalysis = $buyAnalysisService->analyze(
                    currentMarketValue: (float) $marketValue,
                    acquisitionPrice: $acquisitionPrice,
                    value1DayAgo: $value1d,
                    value3DaysAgo: $value3d,
                    value7DaysAgo: $value7d,
                    bidCount: $bidCount,
                    expectedWinningPremium: $premium['premium'],
                    auctionHistorySource: $premium['source'],
                );

                return [
                    'id' => $listing->id,
                    'externalTrend' => $p->externalTrendPayload(),
                    'player' => [
                        'id' => $listing->player->id,
                        'name' => $listing->player->name,
                        'club' => $listing->player->club_name,
                        'clubShort' => $clubShortNames[$listing->player->club_external_id] ?? null,
                        'position' => $listing->player->position,
                        'imageUrl' => $listing->player->image_url,
                        'points' => $listing->player->points,
                        'averagePoints' => (float) $listing->player->average_points,
                    ],
                    'sellerTeam' => $listing->sellerTeam?->name,
                    'marketValue' => $marketValue,
                    'change24h' => $p->trend->change24h,
                    'change3d' => $p->trend->change3d,
                    'trend' => $p->trend->toArray(),
                    // Own-vs-external merged classification (see PlayerTrendPresenter):
                    // `trend.classification` above is *own data only* and defaults to
                    // ESTABLE for most freshly-listed players until a real ~3-day-old
                    // snapshot exists — this is the one the Tendència filter (and the
                    // BUY/SELL/HOLD badge below) actually reflects.
                    'effectiveClassification' => $p->effectiveClassification,
                    'fantasyScore' => $p->score->total,
                    'confidence' => $p->score->confidence,
                    'action' => $buySignal ? 'BUY' : ($sellSignal ? 'SELL' : 'HOLD'),
                    'actionSource' => $p->effectiveSource,
                    'recommendedBid' => (int) round($marketValue * 1.03),
                    'maxBid' => $maxBid,
                    'buyAnalysis' => $buyAnalysis->toArray(),
                    'history' => $p->history,
                    'historySource' => $p->historySource,
                    'expiresAt' => $listing->expires_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        if ($minScore = $request->query('min_score')) {
            $rows = $rows->filter(fn ($r) => $r['fantasyScore'] >= (float) $minScore)->values();
        }

        $sort = $request->query('sort', 'fantasyScore');
        $direction = $request->query('direction', 'desc');
        $sorted = $direction === 'asc' ? $rows->sortBy($sort) : $rows->sortByDesc($sort);

        return response()->json([
            'data' => $sorted->values(),
            'meta' => [
                'totalAvailable' => $totalAvailable,
                'filteredCount' => $rows->count(),
                'availableCapital' => $availableCapital,
                'closesAt' => $closesAt,
            ],
        ]);
    }

    /**
     * Recomputes the Buy Economic Score for one listing at an arbitrary
     * candidate price — BuyEconomicScore(player, acquisitionPrice) — so the
     * UI can answer "what if I bid X instead" without a full market reload.
     * Defaults to the listing's own asking price when none is given, same
     * as index().
     */
    public function buyAnalysis(
        Request $request,
        int $marketPlayer,
        PlayerValueTrendCalculator $trendCalculator,
        MarketBuyAnalysisService $buyAnalysisService,
        MarketAuctionPremiumEstimator $premiumEstimator,
    ) {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;

        if (! $league) {
            return response()->json(['message' => 'No active league selected yet.'], 404);
        }

        $listing = FantasyMarketPlayer::with('player')
            ->where('fantasy_league_id', $league->id)
            ->where('id', $marketPlayer)
            ->first();

        if (! $listing || ! $listing->player) {
            return response()->json(['message' => 'Listing not found.'], 404);
        }

        $marketValue = (float) ($listing->market_value ?? 0);
        $acquisitionPrice = (float) ($request->query('acquisition_price') ?? $listing->asking_price ?? $marketValue);

        $externalTrend = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->where('fantasy_player_id', $listing->player->id)
            ->first();

        [$value1d, $value3d, $value7d] = $trendCalculator->historicalValues($listing->player, $marketValue, $externalTrend);
        $premium = $premiumEstimator->estimate($league);

        $buyAnalysis = $buyAnalysisService->analyze(
            currentMarketValue: $marketValue,
            acquisitionPrice: $acquisitionPrice,
            value1DayAgo: $value1d,
            value3DaysAgo: $value3d,
            value7DaysAgo: $value7d,
            bidCount: $listing->raw_payload['numberOfOffers'] ?? null,
            expectedWinningPremium: $premium['premium'],
            auctionHistorySource: $premium['source'],
        );

        return response()->json($buyAnalysis->toArray());
    }

    public function opportunities(Request $request)
    {
        $account = $this->currentAccount($request);

        $opportunities = FantasyRecommendation::query()
            ->where('fantasy_account_id', $account->id)
            ->where('status', FantasyRecommendation::STATUS_ACTIVE)
            ->where('action', FantasyRecommendation::ACTION_BUY)
            ->with('player')
            ->orderByDesc('confidence')
            ->get();

        return FantasyRecommendationResource::collection($opportunities);
    }
}
