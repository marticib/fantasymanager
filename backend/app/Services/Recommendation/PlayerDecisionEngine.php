<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyOffer;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\ValueObjects\PlayerDecisionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Decides what to do with each of *your own* roster players — HOLD, SELL, or
 * LOCK_CLAUSE (raise the buyout clause) — by comparing three independent
 * 0-100 scores and picking the one with real separation from the runner-up,
 * rather than reacting to any single signal (e.g. "value dropped → sell").
 *
 * - **Hold score**: sporting value (recent form × estimated starter
 *   likelihood), market value trend, scarcity (how much worse the best
 *   market alternative at the same position is), clause protection.
 * - **Sell score**: liquidity need, value expected to be lost over the next
 *   7 days, a realistic cheaper-and-similar alternative on the market, minus
 *   the sporting/market value that would still be lost by selling.
 * - **Clause score**: risk of a rival sniping the clause, market trend,
 *   efficiency of raising it further from here.
 *
 * The winner only counts if it beats the runner-up by
 * `config('fantasy.player_decision.decision_margin')` points (8 by
 * default) — a close call always resolves to HOLD, per design: this engine
 * is meant to flag clear-cut situations, not nudge on noise.
 *
 * Two real data gaps, both gracefully degraded (never fabricated) exactly
 * like FantasyScoreService's `calendar` placeholder:
 * - **Rival cash**: LaLiga's league-scoped team endpoint used for rival
 *   rosters doesn't expose money, and nothing in this app syncs it (see
 *   FantasySyncService — only the account's own team's balance is ever
 *   fetched). "Risk of a rival paying your player's clause" is approximated
 *   from the clause's own premium and market trend alone, not rival
 *   affordability, and this lowers `confidence` accordingly.
 * - **Minutes played / true starter probability**: not present anywhere in
 *   the LaLiga payload. `starterProbability()` is a proxy built from real
 *   per-gameweek points (`raw_payload['weekPoints']`, LaLiga's own
 *   per-matchday scores) and player status — not an invented number, but an
 *   indirect signal, same spirit as FantasyScoreService's own
 *   `starter_likelihood`.
 */
class PlayerDecisionEngine
{
    private const POSITION_AVERAGE_CEILING = [
        'GK' => 6.0,
        'DF' => 6.5,
        'MF' => 7.5,
        'FW' => 8.5,
    ];

    /** A ±2%/day blended growth rate maps the market-trend score onto its full 0-100 range. */
    private const MARKET_TREND_DAILY_BOUNDS = [-0.02, 0.02];

    /** A rival-clause premium at or below 0% is maximal theft risk; at +50% or above, negligible. */
    private const THEFT_PREMIUM_BOUNDS = [0.0, 0.50];

    /** Same tolerance ClauseOpportunityService uses against futbolfantasy scraper mismatches. */
    private const PLAUSIBLE_RATIO_BOUNDS = [1 / 6, 6];

    public function __construct(
        private readonly FantasyScoreService $scoreService,
        private readonly FantasySettingsService $settings,
        private readonly MarketValueProjector $projector,
    ) {
        foreach (['hold_weights', 'sell_weights', 'clause_weights', 'recent_form_weights', 'trade_weights'] as $group) {
            $this->assertWeightsSumToOne(config("fantasy.player_decision.{$group}"), $group);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>> keyed by fantasy_player_id, decision merged with the player's own data
     */
    public function evaluateRoster(FantasyAccount $account): Collection
    {
        $team = $account->activeTeam;

        if (! $team) {
            return collect();
        }

        $teamPlayers = $team->teamPlayers()->with('player')->get()->filter(fn (FantasyTeamPlayer $tp) => $tp->player);
        $rules = $this->settings->rules($account);
        $availableCapital = max(0, (int) ($team->money ?? 0) - (int) $rules['minimum_cash_reserve']);

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $teamPlayers->pluck('fantasy_player_id'))
            ->get()
            ->keyBy('fantasy_player_id');

        $marketByPosition = $this->marketCandidatesByPosition($account);

        return $teamPlayers->mapWithKeys(function (FantasyTeamPlayer $tp) use ($account, $team, $externalTrends, $marketByPosition, $availableCapital) {
            $result = $this->decide(
                $tp->player,
                $tp,
                $team,
                $account,
                $externalTrends->get($tp->fantasy_player_id),
                $marketByPosition->get($tp->player->position, collect()),
                $availableCapital,
            );

            return [$tp->fantasy_player_id => $result];
        });
    }

    private function decide(
        FantasyPlayer $player,
        FantasyTeamPlayer $teamPlayer,
        FantasyTeam $team,
        FantasyAccount $account,
        ?FantasyExternalTrend $externalTrend,
        Collection $marketCandidates,
        int $availableCapital,
    ): PlayerDecisionResult {
        $marketValue = (float) ($player->market_value ?? 0);

        if ($marketValue <= 0) {
            return new PlayerDecisionResult(
                action: 'HOLD',
                holdScore: 50,
                sellScore: 0,
                adjustedSellScore: 0,
                clauseScore: 0,
                tradeScore: 0,
                confidence: 15,
                reason: 'Dades insuficients per avaluar aquest jugador — sense valor de mercat conegut.',
                sellReasonCode: null,
                trade: [],
                clauseTiming: [],
                metrics: [],
            );
        }

        [$value1d, $value3d, $value7d] = $this->historicalValues($player, $marketValue, $externalTrend);
        $growth1d = $this->compoundGrowthRate($marketValue, $value1d, 1);
        $growth3d = $this->compoundGrowthRate($marketValue, $value3d, 3);
        $growth7d = $this->compoundGrowthRate($marketValue, $value7d, 7);
        $dailyGrowth = $this->blendedDailyGrowth($growth1d, $growth3d, $growth7d);
        $hasTrendData = $growth1d !== null || $growth3d !== null || $growth7d !== null;

        $expectedValue7d = $this->projector->project($marketValue, $dailyGrowth, 7);
        $marketScore = $this->scoreFromBounds($dailyGrowth, self::MARKET_TREND_DAILY_BOUNDS);

        $currentMatchday = $account->current_matchday;
        [$expectedPoints, $recentWeeks] = $this->expectedWeeklyPoints($player, $currentMatchday);
        $starterProb = $this->starterProbability($player, $recentWeeks);
        $ceiling = self::POSITION_AVERAGE_CEILING[$player->position] ?? 7.0;
        $sportingScore = $this->clamp(($expectedPoints / $ceiling) * 100, 0, 100) * ($starterProb / 100);

        $ownScore = $this->scoreService->compute($player, $account)->total;
        $bestAlternative = $marketCandidates->sortByDesc('score')->first();
        $scarcityScore = $this->scarcityScore($ownScore, $bestAlternative);

        $clauseValue = $teamPlayer->clause_value ? (float) $teamPlayer->clause_value : null;
        $premiumPct = $clauseValue !== null ? ($clauseValue - $marketValue) / $marketValue : null;
        $theftRisk = $this->theftRiskScore($premiumPct);
        $clauseProtection = 100 - $theftRisk;
        $efficiencyScore = $premiumPct !== null ? $this->clamp(100 - $this->clamp($premiumPct, 0, 1) * 200, 0, 100) : 0;

        $holdWeights = config('fantasy.player_decision.hold_weights');
        $holdScore = $sportingScore * $holdWeights['sporting']
            + $marketScore * $holdWeights['market']
            + $scarcityScore * $holdWeights['scarcity']
            + $clauseProtection * $holdWeights['clause_protection'];

        $avoidedLoss = max(0.0, $marketValue - $expectedValue7d);
        $avoidedLossScore = $this->scoreFromBounds($avoidedLoss / $marketValue, [0.0, 0.10]);
        $liquidityScore = $this->clamp(100 - ($availableCapital / max($marketValue, 1)) * 50, 0, 100);
        $cheaperAlternative = $marketCandidates
            ->filter(fn ($c) => $c['score'] >= $ownScore - 5 && $c['marketValue'] < $marketValue)
            ->sortBy('marketValue')
            ->first();
        $upgradeSavings = $cheaperAlternative ? $marketValue - $cheaperAlternative['marketValue'] : 0.0;
        $upgradeScore = $this->scoreFromBounds($upgradeSavings / $marketValue, [0.0, 0.30]);
        $futureValueScore = ($sportingScore + $marketScore) / 2;

        $sellWeights = config('fantasy.player_decision.sell_weights');
        $sellScore = $liquidityScore * $sellWeights['liquidity']
            + $avoidedLossScore * $sellWeights['avoided_loss']
            + $upgradeScore * $sellWeights['upgrade']
            + (100 - $futureValueScore) * $sellWeights['low_future_value'];

        $clauseWeights = config('fantasy.player_decision.clause_weights');
        $clauseScore = $clauseValue !== null
            ? $theftRisk * $clauseWeights['theft_risk'] + $marketScore * $clauseWeights['market_trend'] + $efficiencyScore * $clauseWeights['efficiency']
            : 0.0;

        // --- Trade Score: "is this a good moment to sell and bank the profit,
        // regardless of whether he's still a good player?" Reuses every growth/
        // projection figure already computed above — never a second, parallel
        // trend calculation. Purely an auxiliary bonus onto the sell verdict
        // (see below), never a fourth final action.
        $appreciation7d = $growth7d !== null ? (1 + $growth7d) ** 7 - 1 : null;
        $appreciationScore = $appreciation7d !== null ? $this->scoreFromBounds($appreciation7d, [0.0, 0.20]) : null;
        $momentumExhaustionScore = $this->momentumExhaustionScore($growth1d, $growth3d);
        $expectedUpside7d = ($expectedValue7d - $marketValue) / $marketValue;
        $futureUpsideScore = $this->scoreFromBounds($expectedUpside7d, [0.0, 0.10]);
        // Trade's own use of "no remaining upside" as a sell-supporting signal
        // only makes sense once we actually know that — with zero trend data,
        // $dailyGrowth silently defaults to 0 (fine for Hold/Sell, which treat
        // that as a conservative "assume nothing" baseline) and would
        // otherwise read here as a falsely confident "no upside left, sell
        // now". Unavailable instead, same as the other trade components.
        $futureUpsideComponent = $hasTrendData ? 100 - $futureUpsideScore : null;

        $currentOfferAmount = $this->currentOffer($player, $team);
        $sellPremium = $currentOfferAmount !== null ? ($currentOfferAmount - $marketValue) / $marketValue : null;
        $sellPremiumScore = $sellPremium !== null ? $this->scoreFromBounds($sellPremium, [0.0, 0.10]) : null;

        $bestAlternativeROI7d = $this->bestAlternativeROI7d($marketCandidates);
        $opportunityGap = $bestAlternativeROI7d !== null ? $bestAlternativeROI7d - $expectedUpside7d : null;
        $capitalEfficiencyScore = $opportunityGap !== null ? $this->scoreFromBounds($opportunityGap, [0.0, 0.08]) : null;

        $tradeWeights = config('fantasy.player_decision.trade_weights');
        $tradeComponentScores = [
            'appreciation' => $appreciationScore,
            'momentum_exhaustion' => $momentumExhaustionScore,
            'sell_premium' => $sellPremiumScore,
            'capital_efficiency' => $capitalEfficiencyScore,
            'future_upside' => $futureUpsideComponent,
        ];
        $tradeScore = $this->weightedScore($tradeComponentScores, $tradeWeights);
        $tradeBonus = max(0.0, $tradeScore - 50) * 0.30;
        $adjustedSellScore = $this->clamp($sellScore + $tradeBonus, 0, 100);

        // --- Decide the natural winner using the *adjusted* sell score, then
        // gate LOCK_CLAUSE behind clause timing (see clauseTiming()): a clause
        // can be worth raising economically while it's still too early to
        // actually spend the money on it.
        $scores = ['HOLD' => $holdScore, 'SELL' => $adjustedSellScore, 'LOCK_CLAUSE' => $clauseScore];
        arsort($scores);
        $ranked = array_keys($scores);
        $margin = (float) config('fantasy.player_decision.decision_margin');
        $naturalAction = ($scores[$ranked[0]] - $scores[$ranked[1]]) >= $margin ? $ranked[0] : 'HOLD';
        $shouldRaiseClause = $naturalAction === 'LOCK_CLAUSE';

        $clauseTiming = $this->clauseTiming($teamPlayer, $shouldRaiseClause, $clauseScore);
        $action = ($shouldRaiseClause && ! $clauseTiming['shouldRaiseNow']) ? 'HOLD' : $naturalAction;

        $sellReasonCode = null;
        if ($action === 'SELL') {
            $sellReasonCode = ($tradeScore >= 75 && $tradeBonus >= 5)
                ? 'TRADE_PROFIT'
                : $this->dominantSellReason($liquidityScore, $avoidedLossScore, $upgradeScore, $futureValueScore, $sellWeights);
        }

        $dataSignals = [$hasTrendData, $recentWeeks !== [], $clauseValue !== null, $marketCandidates->isNotEmpty()];
        $dataQuality = (count(array_filter($dataSignals)) / count($dataSignals)) * 100;
        $gapScore = $this->scoreFromBounds($scores[$ranked[0]] - $scores[$ranked[1]], [0, 30]);
        $confidence = (int) round($this->clamp(0.5 * $gapScore + 0.5 * $dataQuality, 10, 97));

        $tradeDataSignals = [
            $appreciationScore !== null,
            $momentumExhaustionScore !== null,
            $sellPremiumScore !== null,
            $capitalEfficiencyScore !== null,
            $futureUpsideComponent !== null,
        ];
        $trade = [
            'classification' => $this->tradeClassification($tradeScore),
            'appreciation7d' => $appreciation7d !== null ? round($appreciation7d, 4) : null,
            'growth1d' => $growth1d !== null ? round($growth1d, 4) : null,
            'growth3d' => $growth3d !== null ? round($growth3d, 4) : null,
            'growth7d' => $growth7d !== null ? round($growth7d, 4) : null,
            'currentValue' => round($marketValue),
            'currentOffer' => $currentOfferAmount !== null ? round($currentOfferAmount) : null,
            'projectedValue7d' => round($expectedValue7d),
            'expectedUpside7d' => round($expectedUpside7d, 4),
            'bonus' => round($tradeBonus, 1),
            'components' => [
                'appreciation' => $appreciationScore !== null ? (int) round($appreciationScore) : null,
                'momentumExhaustion' => $momentumExhaustionScore !== null ? (int) round($momentumExhaustionScore) : null,
                'sellPremium' => $sellPremiumScore !== null ? (int) round($sellPremiumScore) : null,
                'capitalEfficiency' => $capitalEfficiencyScore !== null ? (int) round($capitalEfficiencyScore) : null,
                'futureUpside' => $futureUpsideComponent !== null ? (int) round($futureUpsideScore) : null,
            ],
            'dataQuality' => (int) round((count(array_filter($tradeDataSignals)) / count($tradeDataSignals)) * 100),
        ];

        $metrics = [
            'marketValue' => $marketValue,
            'expectedValue7d' => round($expectedValue7d),
            'avoidedLoss' => round($avoidedLoss),
            'liquidityImpact' => round($marketValue),
            'availableCapitalAfterSale' => round($availableCapital + $marketValue),
            'dailyGrowth' => round($dailyGrowth, 4),
            'expectedWeeklyPoints' => round($expectedPoints, 2),
            'starterProbability' => round($starterProb),
            'scarcityScore' => round($scarcityScore),
            'bestAlternativeName' => $bestAlternative['player']->name ?? null,
            'cheaperAlternativeName' => $cheaperAlternative['player']->name ?? null,
            'upgradeSavings' => round($upgradeSavings),
            'clausePremiumPct' => $premiumPct !== null ? round($premiumPct * 100, 1) : null,
            'theftRiskScore' => round($theftRisk),
            'lowData' => $dataQuality < 40,
        ];

        return new PlayerDecisionResult(
            action: $action,
            holdScore: (int) round($this->clamp($holdScore, 0, 100)),
            sellScore: (int) round($this->clamp($sellScore, 0, 100)),
            adjustedSellScore: (int) round($adjustedSellScore),
            clauseScore: (int) round($this->clamp($clauseScore, 0, 100)),
            tradeScore: (int) round($this->clamp($tradeScore, 0, 100)),
            confidence: $confidence,
            reason: $this->explain($action, $metrics, $trade, $clauseTiming, $sellReasonCode),
            sellReasonCode: $sellReasonCode,
            trade: $trade,
            clauseTiming: $clauseTiming,
            metrics: $metrics,
        );
    }

    /**
     * Last 4 *played* gameweeks' real points (LaLiga's own `weekPoints`,
     * most-recent first; 0 for a gameweek that happened but the player
     * didn't score in, since that's a real fact) blended with the season
     * average, weights renormalized over however many recent weeks actually
     * exist yet — see config('fantasy.player_decision.recent_form_weights').
     *
     * @return array{0: float, 1: int[]} [expected weekly points, the recent-week point values used]
     */
    private function expectedWeeklyPoints(FantasyPlayer $player, ?int $currentMatchday): array
    {
        $seasonAverage = (float) ($player->average_points ?? 0);
        $weights = config('fantasy.player_decision.recent_form_weights');

        if (! $currentMatchday || $currentMatchday < 2) {
            return [$seasonAverage, []];
        }

        $weekPoints = collect($player->raw_payload['weekPoints'] ?? [])
            ->filter(fn ($w) => isset($w['weekNumber'], $w['points']))
            ->keyBy('weekNumber');

        $recentWeeks = [];
        for ($i = 1; $i <= 4; $i++) {
            $week = $currentMatchday - $i;
            if ($week < 1) {
                break;
            }
            $recentWeeks[] = (int) ($weekPoints->get($week)['points'] ?? 0);
        }

        $weightKeys = ['w1', 'w2', 'w3', 'w4'];
        $usedWeight = $weights['season_avg'];
        $weighted = $weights['season_avg'] * $seasonAverage;

        foreach ($recentWeeks as $i => $points) {
            $usedWeight += $weights[$weightKeys[$i]];
            $weighted += $weights[$weightKeys[$i]] * $points;
        }

        return [$usedWeight > 0 ? $weighted / $usedWeight : $seasonAverage, $recentWeeks];
    }

    /**
     * Proxy for "will he start" — LaLiga's payload carries no minutes or
     * lineup-probability data at all, so this is built from how many of the
     * real recent gameweeks he actually scored in, damped by injury/
     * suspension status.
     */
    private function starterProbability(FantasyPlayer $player, array $recentWeeks): float
    {
        $statusFactor = match (strtolower((string) $player->status)) {
            'injured' => 0.3,
            'doubtful' => 0.6,
            'sanctioned' => 0.4,
            'transferred' => 0.2,
            default => 1.0,
        };

        if (empty($recentWeeks)) {
            $base = ((float) $player->average_points > 0 && $player->points > 0) ? 70.0 : 40.0;

            return $base * $statusFactor;
        }

        $featured = count(array_filter($recentWeeks, fn ($p) => $p > 0));
        $ratio = $featured / count($recentWeeks);

        return min(100, (30 + $ratio * 65) * $statusFactor);
    }

    /**
     * Fantasy Score — and, for the Trade Score's capital-efficiency
     * component, each candidate's own projected 7-day upside, via the exact
     * same growth/projection machinery used for your own players — of every
     * position-matching player currently listed on the league's live market
     * and not already on your roster: the real, live pool of realistic
     * alternatives (same query shape FantasyRecommendationEngine already
     * uses for market opportunities).
     *
     * @return Collection<string, Collection<int, array{player: FantasyPlayer, marketValue: int, score: float, expectedUpside7d: ?float}>> keyed by position
     */
    private function marketCandidatesByPosition(FantasyAccount $account): Collection
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return collect();
        }

        $ownedIds = $team->teamPlayers()->pluck('fantasy_player_id');

        $listings = FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->whereNotIn('fantasy_player_id', $ownedIds)
            ->with('player')
            ->get()
            ->filter(fn (FantasyMarketPlayer $m) => $m->player && $m->player->position);

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $listings->pluck('fantasy_player_id'))
            ->get()
            ->keyBy('fantasy_player_id');

        return $listings
            ->groupBy(fn (FantasyMarketPlayer $m) => $m->player->position)
            ->map(fn (Collection $group) => $group->map(function (FantasyMarketPlayer $m) use ($account, $externalTrends) {
                $player = $m->player;
                $marketValue = (int) ($m->market_value ?: $player->market_value ?: 0);

                return [
                    'player' => $player,
                    'marketValue' => $marketValue,
                    'score' => $this->scoreService->compute($player, $account)->total,
                    'expectedUpside7d' => $marketValue > 0 ? $this->expectedUpside7d($player, (float) $marketValue, $externalTrends->get($player->id)) : null,
                ];
            }));
    }

    /**
     * A market candidate's own projected 7-day value upside — the same
     * historicalValues() → compoundGrowthRate() → blendedDailyGrowth() →
     * MarketValueProjector chain used for your own players in decide(), just
     * without needing all the other Hold/Sell/Clause components alongside it.
     */
    private function expectedUpside7d(FantasyPlayer $player, float $marketValue, ?FantasyExternalTrend $externalTrend): ?float
    {
        [$value1d, $value3d, $value7d] = $this->historicalValues($player, $marketValue, $externalTrend);
        $growth1d = $this->compoundGrowthRate($marketValue, $value1d, 1);
        $growth3d = $this->compoundGrowthRate($marketValue, $value3d, 3);
        $growth7d = $this->compoundGrowthRate($marketValue, $value7d, 7);

        if ($growth1d === null && $growth3d === null && $growth7d === null) {
            return null;
        }

        $dailyGrowth = $this->blendedDailyGrowth($growth1d, $growth3d, $growth7d);
        $projected = $this->projector->project($marketValue, $dailyGrowth, 7);

        return ($projected - $marketValue) / $marketValue;
    }

    /**
     * Best projected 7-day upside among the real market alternatives at this
     * position — `null` (not 0) when none of them have enough history to
     * project, so capital-efficiency stays honestly "unavailable" rather
     * than silently comparing against a fabricated 0% ceiling.
     */
    private function bestAlternativeROI7d(Collection $marketCandidates): ?float
    {
        $values = $marketCandidates->pluck('expectedUpside7d')->filter(fn (?float $v) => $v !== null);

        return $values->isEmpty() ? null : $values->max();
    }

    /**
     * The best (highest, still-pending) real offer *received* for this
     * player, if any. `fantasy_offers` isn't populated by any sync yet in
     * this app (no code path fetches LaLiga's direct-offer/bid data — see
     * FantasyMarketService::getDirectOffer(), only ever called on demand,
     * never scheduled), so this will almost always come back null today —
     * which is exactly the honest "no current offer" the Trade Score's
     * sell-premium component is built to handle, never a fabricated 0.
     */
    private function currentOffer(FantasyPlayer $player, FantasyTeam $team): ?float
    {
        $offer = FantasyOffer::where('fantasy_player_id', $player->id)
            ->where('receiving_team_id', $team->id)
            ->where('status', FantasyOffer::STATUS_PENDING)
            ->orderByDesc('amount')
            ->first();

        return $offer ? (float) $offer->amount : null;
    }

    /**
     * How much worse the best market alternative at the same position is —
     * a big gap means this player is genuinely hard to replace at the same
     * quality (high scarcity, favors HOLD); an available equal-or-better
     * alternative means he's easily replaceable (low scarcity). No
     * candidates at all on the market defaults to high scarcity: there's
     * nothing to even compare against.
     */
    private function scarcityScore(float $ownScore, ?array $bestAlternative): float
    {
        if ($bestAlternative === null) {
            return 90.0;
        }

        $diff = $ownScore - $bestAlternative['score'];

        return $this->scoreFromBounds($diff, [0, 25], floor: 20);
    }

    /**
     * Approximates how tempting your player's clause is to a rival right
     * now. Real rival cash isn't available anywhere in this app (see class
     * docblock) so this leans on what *is* real: how cheap the clause
     * currently is to trigger, relative to market value — a clause priced
     * close to or below market value is trivial for anyone to pay,
     * regardless of who's buying.
     *
     * Deliberately does NOT also factor in the market trend here — that's
     * already `clauseScore`'s separate, equally-weighted `market_trend`
     * component (see decide()). Folding it in twice let an already
     * well-protected, high-premium clause get flagged just because the
     * player's value happened to be spiking, which produced worse advice
     * than the premium alone (caught live against a real account: a 42%
     * premium clause was still recommended for raising).
     */
    private function theftRiskScore(?float $premiumPct): float
    {
        if ($premiumPct === null) {
            return 50.0;
        }

        return 100 - $this->scoreFromBounds($premiumPct, self::THEFT_PREMIUM_BOUNDS);
    }

    /**
     * Values as of 1/3/7 days ago, preferring our own fantasy_player_snapshots
     * and falling back per-window to futbolfantasy's day-offset values only
     * when they're plausible vs. the current value — same guard as
     * ClauseOpportunityService::historicalValues() (a scraper name-mismatch
     * once produced a ~12x-off value there); intentionally duplicated here
     * rather than extracted, to avoid touching that already-shipped service.
     *
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private function historicalValues(FantasyPlayer $player, float $currentValue, ?FantasyExternalTrend $externalTrend): array
    {
        $now = now();
        $snapshots = $player->snapshots()
            ->whereNotNull('market_value')
            ->where('captured_at', '>=', $now->copy()->subDays(10))
            ->orderBy('captured_at')
            ->get(['market_value', 'captured_at']);

        $closestBefore = function (Carbon $target) use ($snapshots): ?float {
            $snapshot = $snapshots->filter(fn (FantasyPlayerSnapshot $s) => $s->captured_at->lessThanOrEqualTo($target))->last();

            return $snapshot?->market_value !== null ? (float) $snapshot->market_value : null;
        };

        $plausibleExternal = function (?int $value) use ($currentValue): ?float {
            if ($value === null || $value <= 0 || $currentValue <= 0) {
                return null;
            }
            $ratio = $currentValue / $value;

            return ($ratio >= self::PLAUSIBLE_RATIO_BOUNDS[0] && $ratio <= self::PLAUSIBLE_RATIO_BOUNDS[1]) ? (float) $value : null;
        };

        return [
            $closestBefore($now->copy()->subDay()) ?? $plausibleExternal($externalTrend?->value_1d),
            $closestBefore($now->copy()->subDays(3)) ?? $plausibleExternal($externalTrend?->value_3d),
            $closestBefore($now->copy()->subDays(7)) ?? $plausibleExternal($externalTrend?->value_7d),
        ];
    }

    private function compoundGrowthRate(float $currentValue, ?float $valueNDaysAgo, int $days): ?float
    {
        if ($valueNDaysAgo === null || $valueNDaysAgo <= 0 || $currentValue <= 0) {
            return null;
        }

        return ($currentValue / $valueNDaysAgo) ** (1 / $days) - 1;
    }

    /**
     * Same weighted blend (and the same configured weights) as
     * ClauseEconomicAnalysisService — "the same weighted trend the clause
     * engine uses", per spec — renormalized over whichever windows have
     * real data. Thin wrapper over the generic weightedScore() below.
     */
    private function blendedDailyGrowth(?float $g1d, ?float $g3d, ?float $g7d): float
    {
        return $this->weightedScore(['1d' => $g1d, '3d' => $g3d, '7d' => $g7d], config('fantasy.clause_analysis.trend_weights'));
    }

    /**
     * Renormalizing weighted average: filters out null components and
     * scales the remaining weights back up to sum to 1 before blending, so
     * a missing signal is excluded rather than silently treated as a 0 —
     * the same renormalization shape used throughout this app's scoring
     * services (blendedDailyGrowth() above, ClauseEconomicAnalysisService's
     * own blendedDailyGrowth()). All-missing returns 0, never NaN.
     *
     * @param  array<string, ?float>  $components
     * @param  array<string, float>  $weights
     */
    private function weightedScore(array $components, array $weights): float
    {
        $available = array_filter($components, fn (?float $v) => $v !== null);

        if (empty($available)) {
            return 0.0;
        }

        $totalWeight = array_sum(array_intersect_key($weights, $available));

        if ($totalWeight <= 0) {
            return 0.0;
        }

        $weighted = array_sum(array_map(fn ($key, $value) => $value * $weights[$key], array_keys($available), $available));

        return $weighted / $totalWeight;
    }

    /**
     * Detects a player who ran hot but is losing steam: ratio = today's
     * daily rate over the 3-day daily rate. ratio ≈ 1 (still pacing with the
     * recent trend) scores low; ratio → 0 (today's move has stalled) scores
     * high. Deliberately requires a real *prior uptrend* (`growth3d > 0`)
     * before scoring exhaustion at all — a player who was already falling
     * has nothing to "exhaust"; that's depreciation, handled by the sell
     * score, not trading (see class docblock, section 10 of the spec this
     * implements). `null` only when there isn't even a 3-day read to judge
     * from; a known flat-or-falling prior trend is a real, if uneventful,
     * answer (0), not missing data.
     */
    private function momentumExhaustionScore(?float $growth1d, ?float $growth3d): ?float
    {
        if ($growth3d === null) {
            return null;
        }

        if ($growth3d <= 0) {
            return 0.0;
        }

        $ratio = $growth1d !== null ? $growth1d / $growth3d : 1.0;

        return $this->clamp(20 + (1 - $ratio) * 80, 0, 100);
    }

    private function tradeClassification(float $tradeScore): string
    {
        return match (true) {
            $tradeScore >= 90 => 'STRONG_SELL',
            $tradeScore >= 75 => 'SELL_FOR_PROFIT',
            $tradeScore >= 60 => 'CONSIDER_SELLING',
            $tradeScore >= 40 => 'WATCH',
            default => 'NO_TRADE',
        };
    }

    /**
     * Which of the original sell-score components actually drove the SELL
     * verdict, for a `sellReasonCode` distinct from TRADE_PROFIT — picks
     * the single largest *weighted* contribution, same idea as sorting the
     * three top-level scores to find the decision's margin.
     */
    private function dominantSellReason(float $liquidityScore, float $avoidedLossScore, float $upgradeScore, float $futureValueScore, array $sellWeights): string
    {
        $contributions = [
            'LIQUIDITY_NEED' => $liquidityScore * $sellWeights['liquidity'],
            'DEPRECIATION' => $avoidedLossScore * $sellWeights['avoided_loss'],
            'CAPITAL_REALLOCATION' => $upgradeScore * $sellWeights['upgrade'],
            'LOW_PERFORMANCE' => (100 - $futureValueScore) * $sellWeights['low_future_value'],
        ];
        arsort($contributions);

        return array_key_first($contributions);
    }

    /**
     * Separates *whether* raising the clause is worth it (`shouldRaise`,
     * decided purely by clauseScore beating the other two) from *when* it's
     * safe to actually spend the money (`shouldRaiseNow`) — raising early
     * buys no extra protection while the clause is still locked, it just
     * ties up cash sooner than necessary. `shouldRaiseNow` triggers once
     * we're inside the last 24h of the current protection window plus a
     * configurable safety margin (default 6h, so a scheduler that only
     * polls every few hours doesn't miss the exact expiry), or immediately
     * if the clause isn't currently locked at all (nothing left to wait
     * for). Recomputed from scratch on every call — nothing here is a
     * stored decision from an earlier evaluation.
     */
    private function clauseTiming(FantasyTeamPlayer $teamPlayer, bool $shouldRaiseClause, float $clauseScore): array
    {
        $lockedUntil = $teamPlayer->clause_locked_until;
        $isLocked = $lockedUntil !== null && $lockedUntil->isFuture();
        $hoursRemaining = $isLocked ? now()->diffInHours($lockedUntil) : null;
        $thresholdHours = 24 + (float) config('fantasy.player_decision.clause_timing.safety_margin_hours');
        $shouldRaiseNow = $shouldRaiseClause && (! $isLocked || $hoursRemaining <= $thresholdHours);

        return [
            'score' => (int) round($this->clamp($clauseScore, 0, 100)),
            'shouldRaise' => $shouldRaiseClause,
            'shouldRaiseNow' => $shouldRaiseNow,
            'locked' => $isLocked,
            'unlockAt' => $lockedUntil?->toIso8601String(),
            'hoursRemaining' => $hoursRemaining !== null ? (int) round($hoursRemaining) : null,
            'daysRemaining' => $hoursRemaining !== null ? (int) ceil($hoursRemaining / 24) : null,
            'recommendedExecution' => match (true) {
                ! $shouldRaiseClause => null,
                $shouldRaiseNow => 'NOW',
                default => 'LAST_PROTECTED_DAY',
            },
        ];
    }

    private function explain(string $action, array $metrics, array $trade, array $clauseTiming, ?string $sellReasonCode): string
    {
        if ($action === 'SELL' && $sellReasonCode === 'TRADE_PROFIT') {
            return implode("\n", array_filter([
                sprintf('Revalorització en 7 dies: %s%%.', number_format(($trade['appreciation7d'] ?? 0) * 100, 1)),
                'La pujada està perdent força.',
                sprintf('Valor estimat en 7 dies: %s.', $this->formatMoney($trade['projectedValue7d'])),
                $trade['currentOffer'] !== null
                    ? 'L\'oferta actual és superior al valor projectat — aprofita-la i realitza benefici.'
                    : 'Aprofita el benefici acumulat abans que la tendència es freni més.',
            ]));
        }

        if ($action === 'HOLD' && ($clauseTiming['shouldRaise'] ?? false) && ! $clauseTiming['shouldRaiseNow']) {
            return implode("\n", array_filter([
                'La clàusula convé pujar-la, però encara no és el moment.',
                $clauseTiming['daysRemaining'] !== null
                    ? sprintf('Encara falten %d %s de protecció — no gastis diners abans d\'hora.', $clauseTiming['daysRemaining'], $clauseTiming['daysRemaining'] === 1 ? 'dia' : 'dies')
                    : null,
                'Es recomana pujar-la l\'últim dia abans que quedi exposat.',
            ]));
        }

        $lines = match ($action) {
            'SELL' => array_filter([
                $metrics['avoidedLoss'] > 0
                    ? sprintf('Es preveu que perdi al voltant de %s aquesta setmana.', $this->formatMoney($metrics['avoidedLoss']))
                    : 'El seu valor no puja de manera rellevant.',
                $metrics['cheaperAlternativeName']
                    ? sprintf('Pots substituir-lo per %s amb un rendiment similar i estalviar %s.', $metrics['cheaperAlternativeName'], $this->formatMoney($metrics['upgradeSavings']))
                    : null,
                'Vendre\'l allibera liquiditat per a futures oportunitats.',
            ]),
            'LOCK_CLAUSE' => array_filter([
                $metrics['clausePremiumPct'] !== null
                    ? sprintf('La clàusula actual només és un %s%% per sobre del valor de mercat.', number_format($metrics['clausePremiumPct'], 1))
                    : null,
                'El seu valor de mercat continua a l\'alça.',
                ($clauseTiming['locked'] ?? false)
                    ? 'El període de protecció està a punt d\'acabar — puja-la abans que quedi exposat.'
                    : 'Ja no té cap protecció activa — puja-la per blindar-la com més aviat millor.',
            ]),
            default => $metrics['lowData']
                ? [
                    'No hi ha prou dades (historial de valor, forma recent o mercat) per prendre una decisió clara.',
                    'Es manté la plantilla tal com està fins que hi hagi més informació.',
                ]
                : array_filter([
                    $metrics['avoidedLoss'] > 0 && $metrics['marketValue'] > 0 && ($metrics['avoidedLoss'] / $metrics['marketValue']) < 0.03
                        ? 'La baixada de valor és petita.'
                        : 'El seu valor es manté estable o a l\'alça.',
                    $metrics['expectedWeeklyPoints'] >= 5 ? 'Continua generant molts punts.' : null,
                    $metrics['scarcityScore'] >= 60 ? 'El cost de substituir-lo és elevat.' : null,
                ]),
        };

        return implode("\n", $lines ?: ['Sense canvis rellevants respecte a la situació actual.']);
    }

    private function formatMoney(float $value): string
    {
        return number_format(abs($value) / 1000, 0).'k€';
    }

    /**
     * Linear map from [$bounds[0], $bounds[1]] onto [$floor, 100], clamped —
     * the same interpolate-and-clamp shape used throughout this app's
     * scoring services (e.g. ClauseEconomicAnalysisService's ROI mapping).
     */
    private function scoreFromBounds(float $value, array $bounds, float $floor = 0.0): float
    {
        [$low, $high] = $bounds;

        if ($value <= $low) {
            return $floor;
        }
        if ($value >= $high) {
            return 100.0;
        }

        return $floor + ($value - $low) / ($high - $low) * (100 - $floor);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function assertWeightsSumToOne(array $weights, string $label): void
    {
        $sum = array_sum($weights);

        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException("fantasy.player_decision.{$label} must sum to 1.0, got {$sum}.");
        }
    }
}
