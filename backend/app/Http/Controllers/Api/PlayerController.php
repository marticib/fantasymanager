<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyPlayerResource;
use App\Models\FantasyAccount;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Services\ExternalData\SparklineHistoryBuilder;
use App\Services\Recommendation\MarketAuctionPremiumEstimator;
use App\Services\Recommendation\MarketBuyAnalysisService;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\PlayerValueTrendCalculator;
use App\Services\Recommendation\RivalClausePresenter;
use App\Services\Recommendation\ValueObjects\MarketBuyAnalysis;
use App\Services\Recommendation\ValueObjects\PlayerDecisionResult;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /** How far back the value chart goes. */
    private const HISTORY_WINDOW_DAYS = 30;

    /** How many economic alternatives the detail page shows. */
    private const ALTERNATIVES_LIMIT = 4;

    public function index(Request $request, PlayerTrendPresenter $presenter)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;
        $query = FantasyPlayer::query();

        if ($position = $request->query('position')) {
            $query->where('position', $position);
        }
        if ($club = $request->query('club')) {
            $query->where('club_name', 'ilike', "%{$club}%");
        }
        if ($search = $request->query('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $players = $query->orderBy('name')->paginate(min((int) $request->query('per_page', 50), 200));
        $playerIds = $players->getCollection()->pluck('id');

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $playerIds)
            ->get()
            ->keyBy('fantasy_player_id');

        // Latest team_players row per player, scoped to the account's own
        // active league — same "who owns this, and what's their clause" the
        // Market/Clauses screens already read, just fetched in bulk for a
        // paginated list. Bug fixed here, confirmed live: this used to query
        // by fantasy_player_id alone with no league scope at all, so a real
        // player owned in a *different* league entirely (someone else's
        // account, or even another of your own leagues) could silently win
        // over the row that actually belongs to the league you're looking
        // at — the fantasy_players catalog is global and shared, but who
        // owns a given player is only ever true within one league.
        $ownerships = $league
            ? FantasyTeamPlayer::whereIn('fantasy_player_id', $playerIds)
                ->whereHas('team', fn ($q) => $q->where('fantasy_league_id', $league->id))
                ->with('team')
                ->get()
                ->groupBy('fantasy_player_id')
                ->map(fn ($rows) => $rows->sortByDesc('id')->first())
            : collect();

        $players->getCollection()->transform(function (FantasyPlayer $player) use ($account, $presenter, $externalTrends, $ownerships) {
            $externalTrend = $externalTrends->get($player->id);
            $p = $presenter->present($player, $account, $externalTrend);
            $teamPlayer = $ownerships->get($player->id);
            $owner = $teamPlayer?->team;

            return array_merge((new FantasyPlayerResource($player))->resolve(), [
                'fantasyScore' => $p->score->total,
                'trend' => $p->trend->toArray(),
                'externalTrend' => $p->externalTrendPayload(),
                'history' => $p->history,
                'historySource' => $p->historySource,
                'owner' => $owner ? ['name' => $owner->manager_name ?: $owner->name, 'isMine' => $owner->is_mine] : null,
                'clauseValue' => $teamPlayer?->clause_value,
            ]);
        });

        return response()->json($players);
    }

    /**
     * The unified player detail screen — one endpoint, but what it returns
     * under `decision` and `alternatives` depends entirely on `context`:
     *
     * - OWNED_BY_ME: PlayerDecisionEngine's roster verdict (HOLD/SELL/LOCK_CLAUSE),
     *   the same one /team reads — never re-derived here.
     * - ON_MARKET: MarketBuyAnalysisService's buy verdict, the same one
     *   /market and "Avui" read.
     * - OWNED_BY_RIVAL (with a clause): RivalClausePresenter, wrapping
     *   ClauseEconomicAnalysisService's verdict — the same one /clauses reads
     *   and the one a rival team's roster page (TeamController::rival()) reuses.
     * - FREE: MarketBuyAnalysisService's buy verdict, run hypothetically
     *   against the player's own market value (no real listing to price against).
     *
     * `favors`/`risks` are the one genuinely new piece of logic here: short
     * deterministic bullets thresholded off each engine's *own* already-computed
     * numbers (ROI, break-even, growth deceleration, premium, ...) — never a
     * second economic formula, and never sporting signals (points, starter
     * status, calendar) inside an economic verdict.
     */
    public function show(
        Request $request,
        FantasyPlayer $player,
        PlayerTrendPresenter $presenter,
        SparklineHistoryBuilder $historyBuilder,
        PlayerDecisionEngine $decisionEngine,
        MarketBuyAnalysisService $buyAnalysisService,
        MarketAuctionPremiumEstimator $premiumEstimator,
        PlayerValueTrendCalculator $trendCalculator,
        RivalClausePresenter $rivalClausePresenter,
    ) {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;
        $externalTrend = FantasyExternalTrend::where('fantasy_player_id', $player->id)->where('source', 'futbolfantasy')->first();
        $p = $presenter->present($player, $account, $externalTrend);

        // Scoped to the account's own active league — real bug fixed here,
        // confirmed live with multiple real users on real separate leagues:
        // this used to query by fantasy_player_id alone with no league
        // scope, so a real player owned in a *different* league (someone
        // else's account, or another of your own leagues) could win over
        // the row for the league you're actually viewing, showing the
        // wrong owner/clause/context. fantasy_players is a global shared
        // catalog, but ownership is only ever true within one league.
        $teamPlayer = $league
            ? FantasyTeamPlayer::where('fantasy_player_id', $player->id)
                ->whereHas('team', fn ($q) => $q->where('fantasy_league_id', $league->id))
                ->latest('id')
                ->first()
            : null;
        $owner = $teamPlayer?->team;

        $listing = $league
            ? FantasyMarketPlayer::where('fantasy_league_id', $league->id)
                ->where('fantasy_player_id', $player->id)
                ->where('is_on_market', true)
                ->first()
            : null;

        $context = $this->ownershipContext($owner, $listing);

        // Deliberately NOT gated on $context === 'OWNED_BY_RIVAL': paying a
        // clause works independently of whether the owner has ALSO listed
        // the player on the market (a rival-owned player who's up for sale
        // gets context ON_MARKET instead, since that's the more actionable
        // card to show — but the clause itself is still payable).
        $clausePurchaseOrder = ($owner && ! $owner->is_mine && $teamPlayer->clause_value !== null)
            ? FantasyClausePurchaseOrder::where('fantasy_account_id', $account->id)
                ->where('fantasy_player_id', $player->id)
                ->whereIn('status', [
                    FantasyClausePurchaseOrder::STATUS_PENDING,
                    FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
                    FantasyClausePurchaseOrder::STATUS_EXECUTED,
                    FantasyClausePurchaseOrder::STATUS_FAILED,
                ])
                ->latest()
                ->first()
            : null;

        $decision = match ($context) {
            'OWNED_BY_ME' => $this->rosterDecision($account, $player, $decisionEngine),
            'ON_MARKET' => $this->buyDecision($player, $listing, $league, $trendCalculator, $premiumEstimator, $buyAnalysisService, $externalTrend),
            'OWNED_BY_RIVAL' => $teamPlayer ? $rivalClausePresenter->present($player, $teamPlayer, $externalTrend) : null,
            // Not on the market, but still analyzable hypothetically — reuses
            // the same buy engine so the page never has to say "not enough
            // data" just because nobody happens to be selling this player.
            'FREE' => $league ? $this->buyDecision($player, null, $league, $trendCalculator, $premiumEstimator, $buyAnalysisService, $externalTrend) : null,
            default => null,
        };

        // Bounded to the chart window — fantasy_player_snapshots only starts
        // accumulating the day an account connects, so even once an account
        // clears the "enough distinct points" bar, those points can all
        // cluster in the last day or two of a 30-day window. This chart
        // prefers futbolfantasy's real day-offset history (see
        // SparklineHistoryBuilder's $preferExternal) precisely because it
        // covers the whole window instead of just the tail end — falling
        // back to "own" only when there's no usable external trend at all.
        $ownSnapshots = $player->snapshots()
            ->whereNotNull('market_value')
            ->where('captured_at', '>=', now()->subDays(self::HISTORY_WINDOW_DAYS))
            ->orderBy('captured_at')
            ->get(['market_value', 'points', 'average_points', 'captured_at']);

        $ownHistory = $ownSnapshots->map(fn ($s) => ['value' => $s->market_value, 'capturedAt' => $s->captured_at->toIso8601String()]);

        [$historyPoints, $historySource] = $historyBuilder->build(
            $ownHistory,
            $externalTrend,
            $player->market_value ?? 0,
            dayOffsets: [30, 14, 7, 3, 1],
            preferExternal: true,
        );

        if ($historySource === 'own') {
            $history = $ownSnapshots->map(fn ($s) => [
                'capturedAt' => $s->captured_at->toIso8601String(),
                'marketValue' => $s->market_value,
                'points' => $s->points,
                'averagePoints' => $s->average_points !== null ? (float) $s->average_points : null,
            ]);
        } else {
            // Only the value is available from futbolfantasy — no points/averagePoints history there.
            $history = collect($historyPoints)->map(fn ($pt) => [
                'capturedAt' => $pt['capturedAt'],
                'marketValue' => $pt['value'],
                'points' => null,
                'averagePoints' => null,
            ]);
        }

        $marketValue = $player->market_value ?? 0;
        $clauseValue = $teamPlayer?->clause_value;
        $lockedUntil = $teamPlayer?->clause_locked_until;

        return response()->json([
            'player' => (new FantasyPlayerResource($player))->resolve(),
            'context' => $context,
            'trend' => $p->trend->toArray(),
            // Unofficial, from futbolfantasy.com — see FutbolFantasySyncService. Informational only.
            'externalTrend' => $p->externalTrendPayload(),
            'fantasyScore' => $p->score->toArray(),
            'history' => $history,
            'historySource' => $historySource,
            'weekPoints' => $this->weekPoints($player),
            'owner' => $owner ? ['name' => $owner->manager_name ?: $owner->name, 'isMine' => $owner->is_mine] : null,
            'clauseValue' => $clauseValue,
            'clausePremiumPct' => ($clauseValue !== null && $marketValue > 0) ? ($clauseValue - $marketValue) / $marketValue : null,
            'clauseLockedUntil' => $lockedUntil?->toIso8601String(),
            'isClauseLocked' => $lockedUntil !== null && $lockedUntil->isFuture(),
            'listing' => $listing ? ['expiresAt' => $listing->expires_at?->toIso8601String(), 'askingPrice' => $listing->asking_price] : null,
            'clausePurchaseOrder' => $clausePurchaseOrder ? [
                'id' => $clausePurchaseOrder->id,
                'status' => $clausePurchaseOrder->status,
                'clauseValueAtOrder' => $clausePurchaseOrder->clause_value_at_order,
                'pendingConfirmationClauseValue' => $clausePurchaseOrder->pending_confirmation_clause_value,
                'executedClauseValue' => $clausePurchaseOrder->executed_clause_value,
                'errorMessage' => $clausePurchaseOrder->error_message,
            ] : null,
            // The decision the relevant engine reached, plus the deterministic
            // favors/risks bullets and the full raw analysis (for "Veure càlcul").
            'decision' => $decision,
            'projections' => $decision['projections'] ?? null,
            'alternatives' => $this->economicAlternatives($player, $league, $trendCalculator, $premiumEstimator, $buyAnalysisService),
            'lastUpdatedAt' => $account->last_synced_at?->toIso8601String(),
        ]);
    }

    /**
     * OWNED_BY_ME takes priority over ON_MARKET (can't buy your own player);
     * otherwise a listing beats plain rival ownership since it's what's
     * actually actionable right now.
     */
    private function ownershipContext(?FantasyTeam $owner, ?FantasyMarketPlayer $listing): string
    {
        if ($owner?->is_mine) {
            return 'OWNED_BY_ME';
        }
        if ($listing) {
            return 'ON_MARKET';
        }
        if ($owner) {
            return 'OWNED_BY_RIVAL';
        }

        return 'FREE';
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterDecision(FantasyAccount $account, FantasyPlayer $player, PlayerDecisionEngine $decisionEngine): array
    {
        // Same call TeamController makes for the whole roster — reused
        // as-is rather than adding a single-player entry point, since a
        // detail-page view isn't a hot path and this never recomputes
        // anything the engine doesn't already do for /team.
        $decision = $decisionEngine->evaluateRoster($account)->get($player->id);

        if (! $decision) {
            return ['type' => 'ROSTER', 'action' => null, 'mainScore' => null, 'confidence' => null, 'reason' => null, 'favors' => [], 'risks' => [], 'raw' => null, 'projections' => null];
        }

        $mainScore = match ($decision->action) {
            'SELL' => $decision->adjustedSellScore,
            'LOCK_CLAUSE' => $decision->clauseScore,
            default => $decision->holdScore,
        };

        ['favors' => $favors, 'risks' => $risks] = $this->rosterFavorsRisks($decision);

        return [
            'type' => 'ROSTER',
            'action' => $decision->action,
            'mainScore' => $mainScore,
            'confidence' => $decision->confidence,
            'reason' => $decision->reason,
            'favors' => $favors,
            'risks' => $risks,
            'raw' => $decision->toArray(),
            'projections' => ($decision->trade['projectedValue7d'] ?? null) !== null
                ? ['value3d' => null, 'value7d' => $decision->trade['projectedValue7d'], 'value14d' => null]
                : null,
        ];
    }

    /**
     * @return array{favors: array<int, string>, risks: array<int, string>}
     */
    private function rosterFavorsRisks(PlayerDecisionResult $decision): array
    {
        $favors = [];
        $risks = [];
        $trade = $decision->trade;
        $metrics = $decision->metrics;
        $timing = $decision->clauseTiming;

        if ($decision->action === 'SELL') {
            if (($trade['currentOffer'] ?? null) !== null && ($trade['projectedValue7d'] ?? null) !== null && $trade['currentOffer'] > $trade['projectedValue7d']) {
                $favors[] = sprintf("L'oferta actual supera el valor projectat a 7 dies en %s.", $this->formatMoney($trade['currentOffer'] - $trade['projectedValue7d']));
            }
            if (($trade['appreciation7d'] ?? null) !== null && $trade['appreciation7d'] > 0) {
                $favors[] = sprintf('Revalorització de %s%% en 7 dies.', number_format($trade['appreciation7d'] * 100, 1));
            }
            if (($metrics['avoidedLoss'] ?? 0) > 0) {
                $risks[] = sprintf('Es preveu que perdi al voltant de %s properament.', $this->formatMoney($metrics['avoidedLoss']));
            }
            if (($trade['currentOffer'] ?? null) === null) {
                $risks[] = 'No hi ha cap oferta real que ho justifiqui.';
            }
        } elseif ($decision->action === 'HOLD') {
            if (($trade['appreciation7d'] ?? null) !== null && $trade['appreciation7d'] > 0) {
                $favors[] = 'Encara manté momentum positiu.';
            }
            if (($trade['projectedValue7d'] ?? null) !== null && $trade['projectedValue7d'] > ($metrics['marketValue'] ?? 0)) {
                $favors[] = 'El valor projectat continua per sobre del valor actual.';
            }
            if (($trade['currentOffer'] ?? null) === null) {
                $favors[] = 'No hi ha cap oferta prou bona.';
            }
            if (($timing['shouldRaise'] ?? false) && ! ($timing['shouldRaiseNow'] ?? false)) {
                $risks[] = 'La clàusula convé pujar-la aviat — no cal fer-ho avui.';
            }
        } elseif ($decision->action === 'LOCK_CLAUSE') {
            if (($metrics['clausePremiumPct'] ?? null) !== null) {
                $favors[] = sprintf('La clàusula actual només és un %s%% per sobre del valor de mercat.', number_format($metrics['clausePremiumPct'], 1));
            }
            $risks[] = ($timing['locked'] ?? false)
                ? "El període de protecció està a punt d'acabar."
                : 'Ja no té cap protecció activa.';
        }

        if ($decision->confidence < 50) {
            $risks[] = 'Historial de dades limitat.';
        }

        return ['favors' => $favors, 'risks' => $risks];
    }

    /**
     * @return array<string, mixed>
     */
    private function buyDecision(
        FantasyPlayer $player,
        ?FantasyMarketPlayer $listing,
        FantasyLeague $league,
        PlayerValueTrendCalculator $trendCalculator,
        MarketAuctionPremiumEstimator $premiumEstimator,
        MarketBuyAnalysisService $buyAnalysisService,
        ?FantasyExternalTrend $externalTrend,
    ): array {
        // FREE players have no real listing/asking price to analyze against —
        // this runs the same economic engine hypothetically, using the
        // player's own market value as a stand-in acquisition price, so the
        // page can still answer "would this be worth chasing if it appeared?".
        $marketValue = (float) ($listing->market_value ?? $player->market_value ?? 0);
        $acquisitionPrice = (float) ($listing->asking_price ?? $marketValue);
        [$value1d, $value3d, $value7d] = $trendCalculator->historicalValues($player, $marketValue, $externalTrend);
        $premium = $premiumEstimator->estimate($league);

        $buy = $buyAnalysisService->analyze(
            currentMarketValue: $marketValue,
            acquisitionPrice: $acquisitionPrice,
            value1DayAgo: $value1d,
            value3DaysAgo: $value3d,
            value7DaysAgo: $value7d,
            bidCount: $listing?->raw_payload['numberOfOffers'] ?? null,
            expectedWinningPremium: $premium['premium'],
            auctionHistorySource: $premium['source'],
        );

        $chaseable = $buy->recommendedBid !== 'DO_NOT_CHASE';
        ['favors' => $favors, 'risks' => $risks] = $this->buyFavorsRisks($buy, $chaseable);

        return [
            'type' => 'BUY',
            'action' => $chaseable ? $buy->recommendation : 'DO_NOT_CHASE',
            'mainScore' => $buy->buyEconomicScore,
            'confidence' => $buy->dataQuality,
            'reason' => null,
            'favors' => $favors,
            'risks' => $risks,
            'raw' => $buy->toArray(),
            'projections' => ['value3d' => $buy->projectedValue3d, 'value7d' => $buy->projectedValue7d, 'value14d' => $buy->projectedValue14d],
        ];
    }

    /**
     * @return array{favors: array<int, string>, risks: array<int, string>}
     */
    private function buyFavorsRisks(MarketBuyAnalysis $buy, bool $chaseable): array
    {
        $favors = [];
        $risks = [];

        if ($buy->expectedROI14d > 0) {
            $favors[] = sprintf('ROI projectat a 14 dies +%s%%.', number_format($buy->expectedROI14d * 100, 1));
        }
        if ($buy->breakEvenDays !== null && $buy->breakEvenDays <= 5) {
            $favors[] = $buy->breakEvenDays === 0 ? 'Ja recupera la inversió avui mateix.' : sprintf('Break-even en %d dies.', $buy->breakEvenDays);
        }
        if ($buy->growth1d !== null && $buy->growth3d !== null && $buy->growth1d > $buy->growth3d) {
            $favors[] = "Momentum positiu — el creixement s'accelera.";
        }

        if ($buy->acquisitionPrice > $buy->currentMarketValue) {
            $premiumPct = ($buy->acquisitionPrice - $buy->currentMarketValue) / $buy->currentMarketValue;
            $risks[] = sprintf('Sobrepreu del %s%% sobre el valor de mercat.', number_format($premiumPct * 100, 1));
        }
        if ($buy->growth1d !== null && $buy->growth3d !== null && $buy->growth1d < $buy->growth3d) {
            $risks[] = "El creixement s'està frenant (growth1d < growth3d).";
        }
        if ($buy->breakEvenDays === null) {
            $risks[] = 'Sense break-even previst dins del termini simulat.';
        } elseif ($buy->breakEvenDays > 10) {
            $risks[] = sprintf('Break-even llarg (%d dies).', $buy->breakEvenDays);
        }
        if (! $chaseable) {
            $risks[] = "L'oferta estimada necessària supera el màxim econòmic.";
        }
        if ($buy->dataQuality < 60) {
            $risks[] = 'Historial de dades limitat.';
        }

        return ['favors' => $favors, 'risks' => $risks];
    }

    /**
     * Real per-gameweek points straight from LaLiga's own payload
     * (`FantasyPlayer::weekPointsBreakdown()`) — the same normalized field
     * PlayerDecisionEngine's recent-form calculation reads, exposed here for
     * the "Punts per jornada" chart rather than re-synthesized.
     *
     * @return array<int, array{weekNumber: int, points: int}>
     */
    private function weekPoints(FantasyPlayer $player): array
    {
        return collect($player->weekPointsBreakdown())
            ->map(fn (int $points, int $weekNumber) => ['weekNumber' => $weekNumber, 'points' => $points])
            ->sortBy('weekNumber')
            ->values()
            ->all();
    }

    /**
     * Economic alternatives, not sporting ones: same position, current market
     * listings only (an owned player isn't something you can go buy instead),
     * each run through the exact same MarketBuyAnalysisService as the player
     * itself would use — ranked by Buy Economic Score, not Fantasy Score.
     *
     * @return array<int, array<string, mixed>>
     */
    private function economicAlternatives(
        FantasyPlayer $player,
        ?FantasyLeague $league,
        PlayerValueTrendCalculator $trendCalculator,
        MarketAuctionPremiumEstimator $premiumEstimator,
        MarketBuyAnalysisService $buyAnalysisService,
    ): array {
        if (! $league || ! $player->position) {
            return [];
        }

        $listings = FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->where('fantasy_player_id', '!=', $player->id)
            ->whereHas('player', fn ($q) => $q->where('position', $player->position))
            ->with('player')
            ->get()
            ->filter(fn (FantasyMarketPlayer $l) => $l->player && $l->market_value);

        if ($listings->isEmpty()) {
            return [];
        }

        $premium = $premiumEstimator->estimate($league);

        return $listings
            ->map(function (FantasyMarketPlayer $listing) use ($trendCalculator, $buyAnalysisService, $premium) {
                $marketValue = (float) $listing->market_value;
                $acquisitionPrice = (float) ($listing->asking_price ?? $marketValue);
                [$value1d, $value3d, $value7d] = $trendCalculator->historicalValues($listing->player, $marketValue, null);

                $buy = $buyAnalysisService->analyze(
                    currentMarketValue: $marketValue,
                    acquisitionPrice: $acquisitionPrice,
                    value1DayAgo: $value1d,
                    value3DaysAgo: $value3d,
                    value7DaysAgo: $value7d,
                    bidCount: $listing->raw_payload['numberOfOffers'] ?? null,
                    expectedWinningPremium: $premium['premium'],
                    auctionHistorySource: $premium['source'],
                );

                return [
                    'id' => $listing->player->id,
                    'name' => $listing->player->name,
                    'marketValue' => $marketValue,
                    'buyEconomicScore' => $buy->buyEconomicScore,
                    'roi14d' => $buy->expectedROI14d,
                    'recommendation' => $buy->recommendedBid !== 'DO_NOT_CHASE' ? $buy->recommendation : 'DO_NOT_CHASE',
                ];
            })
            ->sortByDesc('buyEconomicScore')
            ->take(self::ALTERNATIVES_LIMIT)
            ->values()
            ->all();
    }

    private function formatMoney(float $value): string
    {
        return number_format($value / 1_000_000, 2, ',', '.').' M€';
    }
}
