<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\ValueObjects\MarketBuyAnalysis;
use App\Services\Recommendation\ValueObjects\PlayerDecisionResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "Què he de fer avui?" — the final aggregator, not a fourth decision
 * engine. Every score, projection and decision it reads already comes from
 * PlayerDecisionEngine (own roster: Hold/Sell/Adjusted Sell/Trade/Clause
 * scores, clauseTiming's shouldRaise/shouldRaiseNow split) or
 * MarketBuyAnalysisService (market listings: Buy Economic Score,
 * MaxBid/RecommendedBid, break-even, dataQuality) — this class never
 * recomputes any of that, only ranks and buckets what those already
 * produced into PRIORITAT MÀXIMA / OPORTUNITATS / PLANIFICAT / SEGUIMENT.
 *
 * PriorityScore (0.45 urgency + 0.35 economic impact + 0.20 confidence, all
 * clamped 0-100) is deliberately a *separate* ranking number from any of the
 * functional scores above — it answers "how soon and how much does this
 * matter", not "how good is this player/deal", so it can rank a modest but
 * time-critical clause raise above a big but unhurried trade opportunity.
 */
class TodayActionsService
{
    private readonly array $priorityWeights;

    private readonly array $economicImpactScale;

    public function __construct(
        private readonly PlayerDecisionEngine $decisionEngine,
        private readonly MarketBuyAnalysisService $buyAnalysisService,
        private readonly MarketAuctionPremiumEstimator $premiumEstimator,
        private readonly PlayerValueTrendCalculator $trendCalculator,
        private readonly ClauseOpportunityService $clauseOpportunityService,
        ?array $priorityWeights = null,
        ?array $economicImpactScale = null,
    ) {
        $weights = $priorityWeights ?? config('fantasy.today.priority_weights');
        $this->assertWeightsSumToOne($weights);
        $this->priorityWeights = $weights;
        $this->economicImpactScale = $economicImpactScale ?? config('fantasy.today.economic_impact_scale');
    }

    public function build(FantasyAccount $account): array
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        $actions = [];

        if ($team) {
            $actions = array_merge($actions, $this->rosterActions($account, $team));
        }

        if ($league) {
            $actions = array_merge($actions, $this->marketActions($league));
        }

        if ($team && $league) {
            $actions = array_merge($actions, $this->rivalClauseActions($account));
        }

        $groups = ['urgent' => [], 'opportunity' => [], 'planned' => [], 'watch' => []];

        foreach ($actions as $action) {
            $groups[$action['category']][] = $action;
        }

        foreach ($groups as $key => $list) {
            $groups[$key] = $this->sortActions($list);
        }

        $urgentCount = count($groups['urgent']);
        $opportunityCount = count($groups['opportunity']);
        $plannedCount = count($groups['planned']);
        $watchCount = count($groups['watch']);
        $nothingToDoToday = $urgentCount === 0 && $opportunityCount === 0;

        return [
            'date' => now()->toDateString(),
            'summary' => $this->summaryText($urgentCount, $opportunityCount, $nothingToDoToday),
            'nothingToDoToday' => $nothingToDoToday,
            'urgentCount' => $urgentCount,
            'opportunityCount' => $opportunityCount,
            'plannedCount' => $plannedCount,
            'watchCount' => $watchCount,
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
            'actions' => [
                'urgent' => $groups['urgent'],
                'opportunity' => $groups['opportunity'],
                'planned' => $groups['planned'],
                'watch' => $groups['watch'],
            ],
        ];
    }

    // --- Roster: clause timing + sell/trade, from PlayerDecisionEngine ---

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rosterActions(FantasyAccount $account, FantasyTeam $team): array
    {
        // The exact same call TeamController makes — recomputed fresh every
        // request, never a stale prior plan (see PlayerDecisionEngine's own
        // clause-timing docblock: "recompute from scratch every time").
        $decisions = $this->decisionEngine->evaluateRoster($account);

        $teamPlayers = $team->teamPlayers()->with('player')
            ->get()
            ->filter(fn (FantasyTeamPlayer $tp) => $tp->player)
            ->keyBy('fantasy_player_id');

        $actions = [];

        foreach ($decisions as $playerId => $decision) {
            $teamPlayer = $teamPlayers->get($playerId);

            if (! $teamPlayer) {
                continue;
            }

            $player = $teamPlayer->player;

            if ($clauseAction = $this->clauseAction($player, $teamPlayer, $decision)) {
                $actions[] = $clauseAction;
            }

            if ($sellAction = $this->sellAction($team, $player, $decision)) {
                $actions[] = $sellAction;
            }
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function clauseAction(FantasyPlayer $player, FantasyTeamPlayer $teamPlayer, PlayerDecisionResult $decision): ?array
    {
        $timing = $decision->clauseTiming;

        if (empty($timing) || ! ($timing['shouldRaise'] ?? false)) {
            return null;
        }

        $shouldRaiseNow = (bool) $timing['shouldRaiseNow'];
        $locked = (bool) $timing['locked'];
        $daysRemaining = $timing['daysRemaining'];
        $horizon = (int) config('fantasy.today.planned_horizon_days');

        $urgency = ! $locked
            ? 100.0
            : $this->urgencyFromHours(
                $timing['hoursRemaining'] !== null ? (float) $timing['hoursRemaining'] : null,
                config('fantasy.today.clause_urgency_bands'),
                (float) config('fantasy.today.clause_urgency_beyond'),
                10.0,
            );

        $category = match (true) {
            $shouldRaiseNow => 'urgent',
            $daysRemaining !== null && $daysRemaining <= $horizon => 'planned',
            default => 'watch',
        };

        // No reliable € figure for "value protected by raising this clause"
        // exists anywhere in the app yet — the Clause Score itself is the
        // documented fallback proxy (spec section 5), flagged as such rather
        // than dressed up as a real euro estimate.
        $economicImpact = (int) round($this->clamp((float) $timing['score'], 0, 100));
        $confidence = $decision->confidence;
        $priorityScore = $this->priorityScore($urgency, $economicImpact, $confidence);

        $title = $shouldRaiseNow
            ? 'Pujar clàusula avui'
            : ($daysRemaining !== null
                ? sprintf('Pujar clàusula en %d %s', $daysRemaining, $daysRemaining === 1 ? 'dia' : 'dies')
                : null);

        return [
            'player' => $this->playerPayload($player),
            'type' => $shouldRaiseNow ? 'RAISE_CLAUSE_NOW' : 'RAISE_CLAUSE_PLANNED',
            'category' => $category,
            'priorityScore' => $priorityScore,
            'urgency' => (int) round($urgency),
            'economicImpact' => $economicImpact,
            'economicImpactQuality' => 'proxy',
            'confidence' => $confidence,
            'mainScore' => $timing['score'],
            'recommendation' => $shouldRaiseNow ? 'RAISE_CLAUSE_NOW' : 'RAISE_CLAUSE_PLANNED',
            'reason' => $decision->reason,
            'title' => $category === 'watch' ? null : $title,
            'shortExplanation' => $this->firstLine($decision->reason),
            'deadline' => $timing['unlockAt'],
            'amount' => $teamPlayer->clause_value !== null ? (float) $teamPlayer->clause_value : null,
            'projectedValue' => null,
            'currentValue' => $player->market_value !== null ? (float) $player->market_value : null,
            'currentOffer' => null,
            'recommendedBid' => null,
            'maxBid' => null,
            'clauseUnlockAt' => $timing['unlockAt'],
            'daysRemaining' => $daysRemaining,
            'metadata' => [
                'clauseScore' => $timing['score'],
                'hoursRemaining' => $timing['hoursRemaining'],
                'locked' => $locked,
                'recommendedExecution' => $timing['recommendedExecution'],
                // Two self-computed targets, not a LaLiga-confirmed cap — see
                // PlayerDecisionEngine::clauseTiming()'s docblock.
                'profitableTarget' => $timing['profitableTarget'] ?? null,
                'profitableTargetCost' => $timing['profitableTargetCost'] ?? null,
                'antiTheftTarget' => $timing['antiTheftTarget'] ?? null,
                'antiTheftTargetCost' => $timing['antiTheftTargetCost'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sellAction(FantasyTeam $team, FantasyPlayer $player, PlayerDecisionResult $decision): ?array
    {
        $currentOfferAmount = $decision->trade['currentOffer'] ?? null;
        $projectedValue7d = $decision->trade['projectedValue7d'] ?? null;
        $offerBeatsProjection = $currentOfferAmount !== null && $projectedValue7d !== null && $currentOfferAmount > $projectedValue7d;
        $isSellDecision = $decision->action === 'SELL';

        if (! $isSellDecision && ! $offerBeatsProjection) {
            [$watchLo, $watchHi] = config('fantasy.today.watch_score_range');

            if ($decision->tradeScore >= $watchLo && $decision->tradeScore <= $watchHi) {
                return $this->watchTradeAction($player, $decision, 'trade');
            }
            if ($decision->adjustedSellScore >= $watchLo && $decision->adjustedSellScore <= $watchHi) {
                return $this->watchTradeAction($player, $decision, 'sell');
            }

            return null;
        }

        $expiresAt = null;

        if ($currentOfferAmount !== null) {
            // Same offer PlayerDecisionEngine's Trade Score already resolved
            // (receiving_team_id/status=PENDING/highest amount) — queried
            // again only for its expires_at, a field the decision result
            // doesn't carry, not to re-decide which offer "counts".
            $offer = FantasyOffer::query()
                ->where('receiving_team_id', $team->id)
                ->where('fantasy_player_id', $player->id)
                ->where('status', FantasyOffer::STATUS_PENDING)
                ->orderByDesc('amount')
                ->first(['expires_at']);
            $expiresAt = $offer?->expires_at;
        }

        $noDeadlineUrgency = (float) config('fantasy.today.trade_no_deadline_urgency');
        $urgency = $expiresAt
            ? $this->urgencyFromHours(
                $this->hoursUntil($expiresAt),
                config('fantasy.today.offer_urgency_bands'),
                (float) config('fantasy.today.offer_urgency_beyond'),
                $noDeadlineUrgency,
            )
            : $noDeadlineUrgency;

        $economicImpactRaw = ($currentOfferAmount !== null && $projectedValue7d !== null)
            ? $currentOfferAmount - $projectedValue7d
            : (float) ($decision->metrics['avoidedLoss'] ?? 0);

        $marketValue = (float) ($decision->metrics['marketValue'] ?? $player->market_value ?? 0);
        $economicImpact = $this->economicImpactScore($economicImpactRaw, $marketValue);
        $confidence = $decision->confidence;
        $priorityScore = $this->priorityScore($urgency, $economicImpact, $confidence);

        $isTradeProfit = $decision->sellReasonCode === 'TRADE_PROFIT';
        // A real offer that beats the 7-day projection is worth flagging on
        // its own economic merits even when the broader roster verdict is
        // still HOLD (spec section 10) — not a second sell engine, just this
        // one narrow, already-computed comparison.
        $isAcceptOffer = $currentOfferAmount !== null && $offerBeatsProjection;
        $category = $urgency >= (float) config('fantasy.today.urgent_urgency_threshold')
            && $confidence >= (float) config('fantasy.today.min_confidence_for_priority')
            ? 'urgent'
            : 'opportunity';

        $reason = $isSellDecision
            ? $decision->reason
            : sprintf(
                "L'oferta actual (%s) supera el valor projectat a 7 dies (%s).",
                $this->formatMoney((float) $currentOfferAmount),
                $this->formatMoney((float) $projectedValue7d),
            );

        return [
            'player' => $this->playerPayload($player),
            'type' => $isAcceptOffer ? 'ACCEPT_OFFER' : 'SELL',
            'category' => $category,
            'priorityScore' => $priorityScore,
            'urgency' => (int) round($urgency),
            'economicImpact' => $economicImpact,
            'economicImpactQuality' => $currentOfferAmount !== null ? 'estimated' : 'proxy',
            'confidence' => $confidence,
            'mainScore' => $decision->adjustedSellScore,
            'recommendation' => $isSellDecision ? ($decision->sellReasonCode ?? 'SELL') : 'ACCEPT_OFFER',
            'reason' => $reason,
            'title' => $isTradeProfit ? 'Venda per benefici' : ($isAcceptOffer ? 'Acceptar oferta' : 'Vendre ara'),
            'shortExplanation' => $this->firstLine($reason),
            'deadline' => $expiresAt?->toIso8601String(),
            'amount' => $currentOfferAmount,
            'projectedValue' => $projectedValue7d,
            'currentValue' => $marketValue,
            'currentOffer' => $currentOfferAmount,
            'recommendedBid' => null,
            'maxBid' => null,
            'clauseUnlockAt' => null,
            'daysRemaining' => null,
            'metadata' => [
                'sellScore' => $decision->sellScore,
                'adjustedSellScore' => $decision->adjustedSellScore,
                'tradeScore' => $decision->tradeScore,
                'sellReasonCode' => $decision->sellReasonCode,
                'rosterActionIsSell' => $isSellDecision,
            ],
        ];
    }

    /**
     * SEGUIMENT: a Trade Score or Adjusted Sell Score in the configured
     * "approaching" range (60-74 by default) — a real signal building up,
     * not yet the natural winner over Hold by the required margin.
     */
    private function watchTradeAction(FantasyPlayer $player, PlayerDecisionResult $decision, string $kind): array
    {
        $score = $kind === 'trade' ? $decision->tradeScore : $decision->adjustedSellScore;
        $confidence = $decision->confidence;
        $economicImpact = (int) round($this->clamp((float) $score, 0, 100));
        $priorityScore = $this->priorityScore(15.0, $economicImpact, $confidence);

        return [
            'player' => $this->playerPayload($player),
            'type' => 'WATCH',
            'category' => 'watch',
            'priorityScore' => $priorityScore,
            'urgency' => 15,
            'economicImpact' => $economicImpact,
            'economicImpactQuality' => 'proxy',
            'confidence' => $confidence,
            'mainScore' => $score,
            'recommendation' => 'WATCH',
            'reason' => $decision->reason,
            'title' => null,
            'shortExplanation' => $kind === 'trade'
                ? sprintf('Trade Score %d. La pujada està començant a perdre força.', $score)
                : sprintf('El senyal de venda s\'està apropant (score %d), encara no és prou clar per actuar.', $score),
            'deadline' => null,
            'amount' => null,
            'projectedValue' => null,
            'currentValue' => $player->market_value !== null ? (float) $player->market_value : null,
            'currentOffer' => null,
            'recommendedBid' => null,
            'maxBid' => null,
            'clauseUnlockAt' => null,
            'daysRemaining' => null,
            'metadata' => ['tradeScore' => $decision->tradeScore, 'adjustedSellScore' => $decision->adjustedSellScore],
        ];
    }

    // --- Market: Buy Economic Score, from MarketBuyAnalysisService ---

    /**
     * @return array<int, array<string, mixed>>
     */
    private function marketActions(FantasyLeague $league): array
    {
        // League-wide, computed once — same as MarketController::index().
        $premium = $this->premiumEstimator->estimate($league);

        // Free-market listings only (no seller_team_id) — a listing another
        // manager has put up for sale is a different kind of opportunity
        // (see rivalClauseActions() for the other one Today surfaces:
        // buying via an already-unlocked clause).
        $listings = FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->whereNull('seller_team_id')
            ->with('player')
            ->get()
            ->filter(fn (FantasyMarketPlayer $l) => $l->player && $l->market_value);

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $listings->pluck('fantasy_player_id'))
            ->get()
            ->keyBy('fantasy_player_id');

        $actions = [];

        foreach ($listings as $listing) {
            $player = $listing->player;
            $marketValue = (float) $listing->market_value;
            $acquisitionPrice = (float) ($listing->asking_price ?? $marketValue);
            $externalTrend = $externalTrends->get($player->id);
            [$value1d, $value3d, $value7d] = $this->trendCalculator->historicalValues($player, $marketValue, $externalTrend);

            $buy = $this->buyAnalysisService->analyze(
                currentMarketValue: $marketValue,
                acquisitionPrice: $acquisitionPrice,
                value1DayAgo: $value1d,
                value3DaysAgo: $value3d,
                value7DaysAgo: $value7d,
                bidCount: $listing->raw_payload['numberOfOffers'] ?? null,
                expectedWinningPremium: $premium['premium'],
                auctionHistorySource: $premium['source'],
            );

            if ($action = $this->buyAction($player, $listing->expires_at, $buy)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buyAction(FantasyPlayer $player, ?Carbon $expiresAt, MarketBuyAnalysis $buy): ?array
    {
        // WAIT/DO_NOT_BUY never appear here, however soon the market closes —
        // an imminent deadline never turns a mediocre deal into an opportunity.
        if (! in_array($buy->recommendation, ['BUY', 'CONSIDER'], true)) {
            return null;
        }

        $chaseable = $buy->recommendedBid !== 'DO_NOT_CHASE';

        if ($buy->recommendation === 'CONSIDER') {
            $category = 'watch';
            $urgency = 15.0;
        } else {
            $marketUrgency = $this->urgencyFromHours(
                $this->hoursUntil($expiresAt),
                config('fantasy.today.market_urgency_bands'),
                (float) config('fantasy.today.market_urgency_beyond'),
                20.0,
            );
            // A DO_NOT_CHASE listing is informational only — the number of
            // bidders or how soon the market closes never promotes it to urgent.
            $urgency = $chaseable ? $marketUrgency : min($marketUrgency, 40.0);
            $category = $chaseable && $urgency >= (float) config('fantasy.today.urgent_urgency_threshold')
                ? 'urgent'
                : 'opportunity';
        }

        $economicImpact = $this->economicImpactScore($buy->expectedProfit14d, $buy->currentMarketValue);
        $confidence = $buy->dataQuality;
        $priorityScore = $this->priorityScore($urgency, $economicImpact, $confidence);

        $title = match (true) {
            $category === 'watch' => null,
            ! $chaseable => 'No perseguir la subhasta',
            default => 'Oportunitat de compra',
        };

        return [
            'player' => $this->playerPayload($player),
            'type' => $chaseable ? 'BUY' : 'DO_NOT_CHASE',
            'category' => $category,
            'priorityScore' => $priorityScore,
            'urgency' => (int) round($urgency),
            'economicImpact' => $economicImpact,
            'economicImpactQuality' => 'estimated',
            'confidence' => $confidence,
            'mainScore' => $buy->buyEconomicScore,
            'recommendation' => $chaseable ? $buy->recommendation : 'DO_NOT_CHASE',
            'reason' => $this->buyExplanation($buy, $chaseable),
            'title' => $title,
            'shortExplanation' => $this->buyExplanation($buy, $chaseable),
            'deadline' => $expiresAt?->toIso8601String(),
            'amount' => is_float($buy->recommendedBid) ? $buy->recommendedBid : null,
            'projectedValue' => $buy->projectedValue14d,
            'currentValue' => $buy->currentMarketValue,
            'currentOffer' => null,
            'recommendedBid' => $buy->recommendedBid,
            'maxBid' => $buy->maxBid,
            'clauseUnlockAt' => null,
            'daysRemaining' => null,
            'metadata' => [
                'breakEvenDays' => $buy->breakEvenDays,
                'expectedROI14d' => $buy->expectedROI14d,
                'bidCount' => $buy->bidCount,
                'estimatedWinningBid' => $buy->estimatedWinningBid,
                'auctionHistorySource' => $buy->auctionHistorySource,
                'classification' => $buy->classification,
            ],
        ];
    }

    private function buyExplanation(MarketBuyAnalysis $buy, bool $chaseable): string
    {
        if (! $chaseable) {
            return sprintf(
                "L'oferta estimada necessària (%s) supera el teu màxim econòmic (%s).",
                $this->formatMoney($buy->estimatedWinningBid),
                $this->formatMoney($buy->maxBid),
            );
        }

        if ($buy->recommendation === 'CONSIDER') {
            return sprintf('Buy Economic Score %d — encara no arriba al llindar de compra clara.', $buy->buyEconomicScore);
        }

        $breakEven = match (true) {
            $buy->breakEvenDays === 0 => 'el mateix dia',
            $buy->breakEvenDays !== null => sprintf('%d dies', $buy->breakEvenDays),
            default => 'un termini no determinat',
        };

        return sprintf(
            'ROI a 14 dies %s%%, recupera la inversió en %s.',
            number_format($buy->expectedROI14d * 100, 1),
            $breakEven,
        );
    }

    // --- Rival clauses: only the ones actually payable right now, from ClauseOpportunityService ---

    /**
     * The other half of "opportunities" alongside the free market: a rival's
     * clause, but only once it's genuinely payable — a still-locked clause
     * isn't something you can act on today, however good its economics.
     * Reuses ClauseOpportunityService/ClauseEconomicAnalysisService wholesale
     * (the same engine the /clauses screen uses) — no second clause model.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rivalClauseActions(FantasyAccount $account): array
    {
        $opportunities = $this->clauseOpportunityService->evaluate($account);
        $actions = [];

        foreach ($opportunities as $row) {
            if ($row['isLocked'] || ! in_array($row['economicRecommendation'], ['PAY_CLAUSE', 'CONSIDER'], true)) {
                continue;
            }

            $category = $row['economicRecommendation'] === 'CONSIDER' ? 'watch' : 'opportunity';
            // No reliable deadline exists for "someone else might pay this
            // clause first" — same flat, moderate-low urgency as any other
            // real-but-undated opportunity, never invented precision.
            $urgency = (float) config('fantasy.today.trade_no_deadline_urgency');
            $economicImpact = $this->economicImpactScore((float) $row['profit14d'], (float) $row['marketValue']);
            $confidence = $this->trendCalculator->dataQuality($row['growth1d'], $row['growth3d'], $row['growth7d']);
            $priorityScore = $this->priorityScore($urgency, $economicImpact, $confidence);
            $reason = $this->clauseOpportunityExplanation($row);

            $actions[] = [
                'player' => [
                    'id' => $row['playerId'],
                    'name' => $row['playerName'],
                    'club' => $row['club'],
                    'position' => $row['position'],
                    'imageUrl' => $row['imageUrl'],
                ],
                'type' => 'PAY_CLAUSE',
                'category' => $category,
                'priorityScore' => $priorityScore,
                'urgency' => (int) round($urgency),
                'economicImpact' => $economicImpact,
                'economicImpactQuality' => 'estimated',
                'confidence' => $confidence,
                'mainScore' => $row['clauseEconomicScore'],
                'recommendation' => $row['economicRecommendation'],
                'reason' => $reason,
                'title' => $category === 'watch' ? null : 'Pagar clàusula',
                'shortExplanation' => $reason,
                'deadline' => null,
                'amount' => (float) $row['clauseValue'],
                'projectedValue' => $row['expectedValue14d'],
                'currentValue' => (float) $row['marketValue'],
                'currentOffer' => null,
                'recommendedBid' => null,
                'maxBid' => null,
                'clauseUnlockAt' => null,
                'daysRemaining' => null,
                'metadata' => [
                    'ownerTeamName' => $row['ownerTeamName'],
                    'clauseEconomicScore' => $row['clauseEconomicScore'],
                    'roi14d' => $row['roi14d'],
                    'breakEvenDays' => $row['breakEvenDays'],
                    'affordable' => $row['affordable'],
                ],
            ];
        }

        return $actions;
    }

    private function clauseOpportunityExplanation(array $row): string
    {
        $breakEven = match (true) {
            $row['breakEvenDays'] === 0 => 'el mateix dia',
            $row['breakEvenDays'] !== null => sprintf('%d dies', $row['breakEvenDays']),
            default => 'un termini no determinat',
        };

        return sprintf(
            'Clàusula ja desbloquejada — ROI a 14 dies %s%%, recupera la inversió en %s.',
            number_format($row['roi14d'] * 100, 1),
            $breakEven,
        );
    }

    // --- shared scoring helpers ---

    private function priorityScore(float $urgency, float $economicImpact, float $confidence): int
    {
        $score = $this->priorityWeights['urgency'] * $urgency
            + $this->priorityWeights['economic_impact'] * $economicImpact
            + $this->priorityWeights['confidence'] * $confidence;

        return (int) round($this->clamp($score, 0, 100));
    }

    private function economicImpactScore(float $raw, float $marketValue): int
    {
        if ($marketValue <= 0) {
            return 0;
        }

        return (int) round($this->clamp($this->piecewiseScore($raw / $marketValue, $this->economicImpactScale), 0, 100));
    }

    /**
     * @param  array<int, array{maxHours: int, urgency: int}>  $bands  ascending by maxHours
     */
    private function urgencyFromHours(?float $hours, array $bands, float $beyond, float $default): float
    {
        if ($hours === null) {
            return $default;
        }

        $hours = max(0.0, $hours);

        foreach ($bands as $band) {
            if ($hours <= $band['maxHours']) {
                return (float) $band['urgency'];
            }
        }

        return $beyond;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points  ascending by x, e.g. [[0.0, 0], [0.05, 50], ...]
     */
    private function piecewiseScore(float $x, array $points): float
    {
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        if ($x <= $points[0][0]) {
            return (float) $points[0][1];
        }

        $last = end($points);

        if ($x >= $last[0]) {
            return (float) $last[1];
        }

        for ($i = 0; $i < count($points) - 1; $i++) {
            [$x0, $y0] = $points[$i];
            [$x1, $y1] = $points[$i + 1];

            if ($x >= $x0 && $x <= $x1) {
                $ratio = ($x1 - $x0) > 0 ? ($x - $x0) / ($x1 - $x0) : 0;

                return $y0 + $ratio * ($y1 - $y0);
            }
        }

        return (float) $last[1]; // unreachable — points span [$points[0][0], $last[0]]
    }

    private function hoursUntil(?Carbon $target): ?float
    {
        return $target ? max(0.0, ($target->timestamp - now()->timestamp) / 3600) : null;
    }

    private function firstLine(string $text): string
    {
        return explode("\n", $text)[0];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sortActions(array $actions): array
    {
        usort($actions, function (array $a, array $b) {
            if ($a['priorityScore'] !== $b['priorityScore']) {
                return $b['priorityScore'] <=> $a['priorityScore'];
            }

            $deadlineA = $a['deadline'] ? Carbon::parse($a['deadline'])->timestamp : PHP_INT_MAX;
            $deadlineB = $b['deadline'] ? Carbon::parse($b['deadline'])->timestamp : PHP_INT_MAX;

            if ($deadlineA !== $deadlineB) {
                return $deadlineA <=> $deadlineB;
            }

            return $b['economicImpact'] <=> $a['economicImpact'];
        });

        return array_values($actions);
    }

    private function summaryText(int $urgentCount, int $opportunityCount, bool $nothingToDoToday): string
    {
        if ($nothingToDoToday) {
            return 'Avui no cal fer res. No hi ha cap acció econòmica prioritària.';
        }

        $parts = [];

        if ($urgentCount > 0) {
            $parts[] = $urgentCount.' '.($urgentCount === 1 ? 'acció prioritària' : 'accions prioritàries');
        }

        if ($opportunityCount > 0) {
            $parts[] = $opportunityCount.' '.($opportunityCount === 1 ? 'oportunitat' : 'oportunitats');
        }

        return implode(' · ', $parts);
    }

    private function playerPayload(FantasyPlayer $player): array
    {
        return [
            'id' => $player->id,
            'name' => $player->name,
            'club' => $player->club_name,
            'position' => $player->position,
            'imageUrl' => $player->image_url,
        ];
    }

    private function formatMoney(float $value): string
    {
        return number_format($value / 1_000_000, 2, ',', '.').' M€';
    }

    private function assertWeightsSumToOne(array $weights): void
    {
        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException("fantasy.today.priority_weights must sum to 1.0, got {$sum}.");
        }
    }
}
