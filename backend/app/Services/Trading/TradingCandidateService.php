<?php

namespace App\Services\Trading;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\ClauseEconomicAnalysisService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\MarketAuctionPremiumEstimator;
use App\Services\Recommendation\MarketBuyAnalysisService;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\PlayerValueTrendCalculator;
use App\Services\Recommendation\ValueObjects\ClauseEconomicAnalysis;
use App\Services\Recommendation\ValueObjects\MarketBuyAnalysis;
use Illuminate\Support\Carbon;

/**
 * The single pool of economic candidates both Trading tabs optimize over:
 * your own players (OWN), market listings (MARKET) and rival clauses
 * (CLAUSE). It owns no projection maths — every projection, profit, ROI,
 * MaxBid and recommended bid is read straight off MarketBuyAnalysisService /
 * ClauseEconomicAnalysisService (see their docblocks); what this class adds is
 * only what is specific to the Trading screen:
 *
 * - Data-source split. Economic value and history (1d/3d/7d) come from
 *   futbolfantasy (FantasyExternalTrend) and are NEVER mixed with our own
 *   snapshots or LaLiga's market_value; league state (cash, roster, listing,
 *   asking price, bid count, clause, lock/shield) comes from LaLiga. A player
 *   with no FF match, no usable window or an implausible value is excluded (or,
 *   for an own player, just left without an evolution) — a missing figure is
 *   null, never 0.
 * - Acquisition cost. Market: the engine's recommended bid, floored at the
 *   asking price and only if it stays under MaxBid; clause: the clause value;
 *   own: 0 (already paid for).
 * - A per-candidate confidence computed from the real data quality.
 *
 * Every row is a plain array so it can be serialized as-is; money fields are
 * whole euros, percentages are decimal fractions (0.125 = 12.5%) except the raw
 * FF `pct*` figures, which keep FF's own percentage unit. Per-horizon maps
 * (`projected`, `profit`, `roi`, `evolution`) are keyed by 3, 7 and 14.
 */
class TradingCandidateService
{
    public const HORIZONS = [3, 7, 14];

    public function __construct(
        private readonly FantasySettingsService $settings,
        private readonly MarketBuyAnalysisService $buyAnalysis,
        private readonly ClauseEconomicAnalysisService $clauseAnalysis,
        private readonly MarketAuctionPremiumEstimator $premiumEstimator,
        private readonly PlayerValueTrendCalculator $trendCalculator,
        private readonly PlayerDecisionEngine $decisionEngine,
    ) {}

    /**
     * @return array{
     *     team: FantasyTeam,
     *     cash: int,
     *     rules: array<string, mixed>,
     *     auctionPremium: array{premium: float, source: string, sampleCount: int},
     *     own: list<array<string, mixed>>,
     *     market: list<array<string, mixed>>,
     *     clause: list<array<string, mixed>>,
     *     matching: array<string, int>
     * }|null null when the account has no active league/team yet
     */
    public function pool(FantasyAccount $account): ?array
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return null;
        }

        $rules = $this->settings->rules($account);
        $premium = $this->premiumEstimator->estimate($league);

        $ownEntries = FantasyTeamPlayer::where('fantasy_team_id', $team->id)->with('player')->get()->filter(fn ($e) => $e->player);
        $ownIds = $ownEntries->pluck('fantasy_player_id')->all();

        $listings = FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->where('is_on_market', true)
            ->with(['player', 'sellerTeam'])
            ->get()
            ->filter(fn (FantasyMarketPlayer $l) => $l->player && ! in_array($l->fantasy_player_id, $ownIds, true) && $l->seller_team_id !== $team->id);

        $clauseEntries = FantasyTeamPlayer::query()
            ->whereHas('team', fn ($q) => $q->where('fantasy_league_id', $league->id)->where('is_mine', false))
            ->whereNotNull('clause_value')
            ->with(['player', 'team'])
            ->get()
            ->filter(fn (FantasyTeamPlayer $e) => $e->player);

        $playerIds = $ownEntries->pluck('fantasy_player_id')
            ->merge($listings->pluck('fantasy_player_id'))
            ->merge($clauseEntries->pluck('fantasy_player_id'))
            ->unique()->values();

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $playerIds)
            ->get()
            ->keyBy('fantasy_player_id');

        $own = $ownEntries->map(fn ($e) => $this->ownCandidate($e, $team, $externalTrends->get($e->fantasy_player_id)))->values()->all();
        $market = $listings->map(fn ($l) => $this->marketCandidate($l, $rules, $premium, $externalTrends->get($l->fantasy_player_id)))->values()->all();
        $clause = $clauseEntries->map(fn ($e) => $this->clauseCandidate($e, $externalTrends->get($e->fantasy_player_id)))->values()->all();

        return [
            'team' => $team,
            'cash' => (int) ($team->money ?? 0),
            'rules' => $rules,
            'auctionPremium' => $premium,
            'own' => $own,
            'market' => $market,
            'clause' => $clause,
            'matching' => $this->matchingSummary([...$own, ...$market, ...$clause]),
        ];
    }

    /**
     * The row as the API returns it for one horizon: the per-horizon maps
     * collapse to plain `projectedValue` / `gain` / `roi` figures, everything
     * else that identifies the player, the price and the data behind it is
     * passed through untouched. `gain` is the acquisition profit for MARKET /
     * CLAUSE rows and the expected evolution (null when unknown) for OWN ones.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function view(array $row, int $horizon): array
    {
        $isOwn = $row['source'] === 'OWN';
        $passthrough = [
            'source', 'playerId', 'name', 'club', 'position', 'imageUrl', 'marketValue', 'economicValue',
            'external', 'confidence', 'warnings', 'excludeReason',
            'listingId', 'sellerTeam', 'expiresAt', 'askingPrice', 'bidCount', 'maxBid', 'engineMaxBid',
            'recommendedBid', 'estimatedWinningBid', 'auctionHistorySource',
            'ownerTeamName', 'clauseValue', 'isLocked', 'isShielded', 'clauseLockedUntil', 'daysUntilUnlock',
            'isStarter', 'salePrice', 'saleKind', 'saleCertainty', 'breakEvenDays', 'economicScore', 'growth',
        ];

        return array_merge(array_intersect_key($row, array_flip($passthrough)), [
            'cost' => $row['acquisitionCost'],
            'projectedValue' => $row['projected'][$horizon] ?? null,
            'gain' => $isOwn ? ($row['evolution'][$horizon] ?? null) : ($row['profit'][$horizon] ?? null),
            'roi' => $isOwn ? null : ($row['roi'][$horizon] ?? null),
            'excludeLabel' => TradingExplainer::excludeLabel($row['excludeReason'] ?? null),
        ]);
    }

    /**
     * Counts per exclusion reason plus the first rows, for "why isn't X here?".
     *
     * @param  list<array<string, mixed>>  $excluded  rows with an `excludeReason`
     * @return array{counts: array<string, int>, total: int, items: list<array<string, mixed>>}
     */
    public function excludedSummary(array $excluded, int $horizon, int $limit = 60): array
    {
        $counts = [];
        foreach ($excluded as $row) {
            $counts[$row['excludeReason']] = ($counts[$row['excludeReason']] ?? 0) + 1;
        }
        ksort($counts);

        return [
            'counts' => $counts,
            'total' => count($excluded),
            'items' => array_map(fn ($r) => $this->view($r, $horizon), array_slice($excluded, 0, $limit)),
        ];
    }

    /**
     * How well futbolfantasy covers this pool: rows whose FF data is usable,
     * rows with no match at all (unmatched or ambiguous — the matcher drops
     * ambiguous names without recording them), and rows dropped for
     * insufficient or implausible FF data.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, matched: int, noMatch: int, insufficientData: int, suspectValue: int}
     */
    private function matchingSummary(array $rows): array
    {
        $noMatch = $insufficient = $suspect = 0;
        foreach ($rows as $row) {
            $reason = $row['excludeReason'] ?? null;
            $warnings = $row['warnings'] ?? [];
            if ($row['external']['matchStatus'] === 'NO_MATCH') {
                $noMatch++;
            } elseif ($reason === 'INSUFFICIENT_FF_DATA' || in_array('INSUFFICIENT_FF_DATA', $warnings, true)) {
                $insufficient++;
            } elseif ($reason === 'SUSPECT_VALUE_MISMATCH' || in_array('SUSPECT_VALUE_MISMATCH', $warnings, true)) {
                $suspect++;
            }
        }

        return [
            'total' => count($rows),
            'matched' => count($rows) - $noMatch,
            'noMatch' => $noMatch,
            'insufficientData' => $insufficient,
            'suspectValue' => $suspect,
        ];
    }

    /** @return array<string, mixed> */
    private function ownCandidate(FantasyTeamPlayer $entry, FantasyTeam $team, ?FantasyExternalTrend $ext): array
    {
        $player = $entry->player;
        $laligaValue = $player->market_value ? (float) $player->market_value : null;
        $ff = $this->externalContext($ext, $laligaValue);

        $row = $this->baseRow($player, 'OWN', $ext, $ff, $laligaValue);
        $row['isStarter'] = (bool) $entry->is_starter;
        $row['acquisitionCost'] = 0;
        $row['costKind'] = 'OWNED';
        $row['evolution'] = null;
        $row['projected'] = null;
        $row['excludeReason'] = null;
        $row['eligible'] = true;

        $offer = $this->decisionEngine->currentOffer($player, $team);
        $row['saleKind'] = $offer !== null ? 'OFFER' : 'ESTIMATE';
        // A real pending offer if there is one; otherwise an *estimate* (FF's
        // value when we have it, else LaLiga's) — never presented as an offer.
        $row['salePrice'] = (int) round($offer ?? $ff['value'] ?? $laligaValue ?? 0) ?: null;
        $row['saleCertainty'] = (int) config('fantasy.trading.cost_certainty.'.($offer !== null ? 'sale_offer' : 'sale_estimate'));

        if (! $ff['ok']) {
            $row['warnings'][] = $ff['reason'];
            $row['confidence'] = $this->confidence('owned', $ext, [null, null, null], $ff['divergence'], null);

            return $row;
        }

        // The projection is independent of price; acquisition = current
        // economic value just makes `expectedProfit` read as the evolution.
        $analysis = $this->buyAnalysis->analyze($ff['value'], $ff['value'], $ff['hist'][0], $ff['hist'][1], $ff['hist'][2], null, 0.0, 'none', $laligaValue);

        $row['projected'] = $this->projectedMap($analysis);
        $row['evolution'] = $this->profitMap($analysis);
        $row['growth'] = $this->growthBlock($analysis);
        $row['confidence'] = $this->confidence('owned', $ext, [$analysis->growth1d, $analysis->growth3d, $analysis->growth7d], $ff['divergence'], null);

        return $row;
    }

    /**
     * @param  array{premium: float, source: string, sampleCount: int}  $premium
     * @return array<string, mixed>
     */
    private function marketCandidate(FantasyMarketPlayer $listing, array $rules, array $premium, ?FantasyExternalTrend $ext): array
    {
        $player = $listing->player;
        $laligaValue = (float) ($listing->market_value ?: ($player->market_value ?? 0)) ?: null;
        $asking = (float) ($listing->asking_price ?? $laligaValue ?? 0);
        $ff = $this->externalContext($ext, $laligaValue);
        $bidCount = $listing->raw_payload['numberOfOffers'] ?? null;

        $row = $this->baseRow($player, 'MARKET', $ext, $ff, $laligaValue);
        $row += [
            'listingId' => $listing->id,
            'sellerTeam' => $listing->sellerTeam?->name,
            'expiresAt' => $listing->expires_at?->toIso8601String(),
            'askingPrice' => $asking > 0 ? (int) round($asking) : null,
            'bidCount' => $bidCount,
            'costKind' => 'RECOMMENDED_BID',
            'auctionHistorySource' => $premium['source'],
            'acquisitionCost' => null,
            'maxBid' => null,
            'engineMaxBid' => null,
            'recommendedBid' => null,
            'estimatedWinningBid' => null,
            'projected' => null,
            'profit' => null,
            'roi' => null,
            'eligible' => false,
        ];

        if (! $ff['ok']) {
            return $this->exclude($row, $ff['reason'], $ext, null, 'market_'.($premium['source'] === 'league' ? 'league' : 'fallback'), $ff['divergence']);
        }
        if ($laligaValue === null || $asking <= 0) {
            return $this->exclude($row, 'NO_PRICE', $ext, null, 'market_fallback', $ff['divergence']);
        }

        // First pass at the asking price only to learn the engine's MaxBid and
        // recommended bid (both depend on the projection, not on the price).
        $probe = $this->buyAnalysis->analyze($ff['value'], $asking, $ff['hist'][0], $ff['hist'][1], $ff['hist'][2], $bidCount, $premium['premium'], $premium['source'], $laligaValue);

        $capCeiling = $laligaValue * (1 + ((float) $rules['maximum_bid_over_market_percentage'] / 100));
        $effectiveMaxBid = (int) floor(min($probe->maxBid, $capCeiling));

        $row['engineMaxBid'] = (int) floor($probe->maxBid);
        $row['maxBid'] = $effectiveMaxBid;
        $row['estimatedWinningBid'] = (int) round($probe->estimatedWinningBid);
        $row['projected'] = $this->projectedMap($probe);
        $row['growth'] = $this->growthBlock($probe);

        $certaintyKey = 'market_'.($premium['source'] === 'league' ? 'league' : 'fallback');
        $growths = [$probe->growth1d, $probe->growth3d, $probe->growth7d];

        if ($probe->recommendedBid === 'DO_NOT_CHASE') {
            $row['recommendedBid'] = 'DO_NOT_CHASE';

            return $this->exclude($row, 'DO_NOT_CHASE', $ext, $growths, $certaintyKey, $ff['divergence']);
        }

        // The league's typical winning bid, but never below what the listing
        // asks, and — the hard rule — never above MaxBid.
        $cost = (int) ceil(max((float) $probe->recommendedBid, $asking));
        $row['recommendedBid'] = $cost;

        if ($cost > $effectiveMaxBid) {
            return $this->exclude($row, 'MAX_BID_TOO_LOW', $ext, $growths, $certaintyKey, $ff['divergence']);
        }

        $analysis = $this->buyAnalysis->analyze($ff['value'], (float) $cost, $ff['hist'][0], $ff['hist'][1], $ff['hist'][2], $bidCount, $premium['premium'], $premium['source'], $laligaValue);

        $row['acquisitionCost'] = $cost;
        $row['profit'] = $this->profitMap($analysis);
        $row['roi'] = $this->roiMap($analysis);
        $row['breakEvenDays'] = $analysis->breakEvenDays;
        $row['economicScore'] = $analysis->buyEconomicScore;
        $row['economicRecommendation'] = $analysis->recommendation;
        $row['confidence'] = $this->confidence('market', $ext, $growths, $ff['divergence'], $certaintyKey);

        return $this->finalizeEligibility($row);
    }

    /** @return array<string, mixed> */
    private function clauseCandidate(FantasyTeamPlayer $entry, ?FantasyExternalTrend $ext): array
    {
        $player = $entry->player;
        $laligaValue = $player->market_value ? (float) $player->market_value : null;
        $clauseValue = (float) $entry->clause_value;
        $ff = $this->externalContext($ext, $laligaValue);

        $isLocked = $entry->clause_locked_until !== null && Carbon::parse($entry->clause_locked_until)->isFuture();
        $isShielded = (bool) $entry->is_locked;

        $row = $this->baseRow($player, 'CLAUSE', $ext, $ff, $laligaValue);
        $row += [
            'ownerTeamId' => $entry->fantasy_team_id,
            'ownerTeamName' => $entry->team?->name,
            'clauseValue' => (int) $clauseValue,
            'isLocked' => $isLocked,
            'isShielded' => $isShielded,
            'clauseLockedUntil' => $entry->clause_locked_until?->toIso8601String(),
            'daysUntilUnlock' => $isLocked ? (int) ceil(now()->diffInHours(Carbon::parse($entry->clause_locked_until)) / 24) : null,
            'costKind' => 'CLAUSE',
            'acquisitionCost' => (int) $clauseValue,
            'projected' => null,
            'profit' => null,
            'roi' => null,
            'eligible' => false,
        ];

        if (! $ff['ok']) {
            return $this->exclude($row, $ff['reason'], $ext, null, 'clause', $ff['divergence']);
        }

        $analysis = $this->clauseAnalysis->analyze($ff['value'], $clauseValue, $ff['hist'][0], $ff['hist'][1], $ff['hist'][2]);
        $growths = [$analysis->growth1d, $analysis->growth3d, $analysis->growth7d];

        $row['projected'] = [3 => (int) round($analysis->expectedValue3d), 7 => (int) round($analysis->expectedValue7d), 14 => (int) round($analysis->expectedValue14d)];
        $row['profit'] = [3 => (int) round($analysis->profit3d), 7 => (int) round($analysis->profit7d), 14 => (int) round($analysis->profit14d)];
        $row['roi'] = [3 => $analysis->roi3d, 7 => $analysis->roi7d, 14 => $analysis->roi14d];
        $row['growth'] = $this->clauseGrowthBlock($analysis);
        $row['breakEvenDays'] = $analysis->breakEvenDays;
        $row['economicScore'] = $analysis->clauseEconomicScore;
        $row['economicRecommendation'] = $analysis->economicRecommendation;
        $row['confidence'] = $this->confidence('clause', $ext, $growths, $ff['divergence'], 'clause');

        // Lock/shield make the clause unpayable *today*: kept in the response
        // (with when it unlocks) but never proposed as an action now.
        if ($isShielded) {
            $row['excludeReason'] = 'CLAUSE_SHIELDED';

            return $row;
        }
        if ($isLocked) {
            $row['excludeReason'] = 'CLAUSE_LOCKED';

            return $row;
        }

        return $this->finalizeEligibility($row);
    }

    /** @return array<string, mixed> */
    private function baseRow(FantasyPlayer $player, string $source, ?FantasyExternalTrend $ext, array $ff, ?float $laligaValue): array
    {
        return [
            'source' => $source,
            'playerId' => $player->id,
            'name' => $player->name,
            'club' => $player->club_name,
            'position' => $player->position,
            'imageUrl' => $player->image_url,
            'marketValue' => $laligaValue !== null ? (int) round($laligaValue) : null,
            'economicValue' => $ff['value'] !== null ? (int) round($ff['value']) : null,
            'external' => $this->externalBlock($ext, $ff),
            'confidence' => null,
            'excludeReason' => null,
            'warnings' => $ff['warnings'],
        ];
    }

    /**
     * futbolfantasy context for one player: whether its value/history is usable
     * as the economic basis, and why not when it isn't.
     *
     * @return array{ok: bool, reason: ?string, value: ?float, hist: array{0: ?float, 1: ?float, 2: ?float}, divergence: ?float, warnings: list<string>}
     */
    private function externalContext(?FantasyExternalTrend $ext, ?float $laligaValue): array
    {
        $none = ['ok' => false, 'reason' => null, 'value' => null, 'hist' => [null, null, null], 'divergence' => null, 'warnings' => []];

        if (! $ext) {
            return ['reason' => 'NO_MATCH'] + $none;
        }

        $value = $ext->value_now !== null && $ext->value_now > 0 ? (float) $ext->value_now : null;

        if ($value === null) {
            return ['reason' => 'INSUFFICIENT_FF_DATA'] + $none;
        }

        $divergence = $laligaValue !== null && $laligaValue > 0 ? abs($value / $laligaValue - 1) : null;

        if ($divergence !== null && $divergence > (float) config('fantasy.trading.value_divergence.max')) {
            return ['reason' => 'SUSPECT_VALUE_MISMATCH', 'value' => $value, 'divergence' => $divergence] + $none;
        }

        $hist = $this->trendCalculator->externalHistoricalValues($ext, $value);

        if ($hist === [null, null, null]) {
            return ['reason' => 'INSUFFICIENT_FF_DATA', 'value' => $value, 'divergence' => $divergence] + $none;
        }

        $warnings = [];
        if ($divergence !== null && $divergence > (float) config('fantasy.trading.value_divergence.warn')) {
            $warnings[] = 'VALUE_DIVERGENCE';
        }
        if (in_array(null, $hist, true)) {
            $warnings[] = 'PARTIAL_HISTORY';
        }

        return ['ok' => true, 'reason' => null, 'value' => $value, 'hist' => $hist, 'divergence' => $divergence, 'warnings' => $warnings];
    }

    /** @return array<string, mixed> */
    private function externalBlock(?FantasyExternalTrend $ext, array $ff): array
    {
        if (! $ext) {
            // The matcher discards ambiguous names without persisting them, so
            // "unmatched" and "ambiguous" are indistinguishable from here.
            return ['matchStatus' => 'NO_MATCH', 'matchConfidence' => null, 'fetchedAt' => null, 'valueDivergence' => null];
        }

        $pct = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'matchStatus' => 'MATCHED',
            'matchConfidence' => $ext->match_confidence,
            'fetchedAt' => $ext->fetched_at?->toIso8601String(),
            'valueNow' => $ext->value_now,
            'pct1d' => $pct($ext->pct_1d),
            'pct3d' => $pct($ext->pct_3d),
            'pct7d' => $pct($ext->pct_7d),
            'pct14d' => $pct($ext->pct_14d),
            'trendDays' => $ext->trend_days,
            'decelerating' => $ext->decelerating,
            'valueDivergence' => $ff['divergence'],
        ];
    }

    /**
     * @param  array<int, ?float>|null  $growths  1d/3d/7d compound daily growth
     * @return array{score: int, level: string, components: array<string, int>}
     */
    private function confidence(string $costKind, ?FantasyExternalTrend $ext, ?array $growths, ?float $divergence, ?string $certaintyKey): array
    {
        $cfg = config('fantasy.trading');
        $components = [];

        $components['match'] = $ext ? (int) ($cfg['match_quality'][$ext->match_confidence] ?? 60) : 0;
        $components['windows'] = $growths ? $this->trendCalculator->dataQuality(...array_pad($growths, 3, null)) : 0;
        $components['freshness'] = $this->freshnessScore($ext?->fetched_at, (int) $cfg['staleness_minutes']['external']);
        $components['cost_certainty'] = (int) ($cfg['cost_certainty'][$certaintyKey ?? $costKind] ?? 100);
        $components['value_consistency'] = $divergence === null ? 50 : (int) round(100 * (1 - min(1.0, $divergence / (float) $cfg['value_divergence']['max'])));

        $weights = $cfg['confidence_weights'];
        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += $weight * ($components[$key] ?? 0);
        }
        $score = (int) round($score / (array_sum($weights) ?: 1));

        $level = $score >= $cfg['confidence_bands']['high'] ? 'HIGH' : ($score >= $cfg['confidence_bands']['medium'] ? 'MEDIUM' : 'LOW');

        return ['score' => $score, 'level' => $level, 'components' => $components];
    }

    private function freshnessScore(?Carbon $fetchedAt, int $thresholdMinutes): int
    {
        if (! $fetchedAt) {
            return 0;
        }

        $age = $fetchedAt->diffInMinutes(now(), true);

        if ($age <= $thresholdMinutes) {
            return 100;
        }

        // Linear from fresh at the threshold down to 0 at three times it.
        return (int) max(0, round(100 * (1 - ($age - $thresholdMinutes) / (2 * $thresholdMinutes))));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, ?float>|null  $growths
     * @return array<string, mixed>
     */
    private function exclude(array $row, string $reason, ?FantasyExternalTrend $ext, ?array $growths, string $certaintyKey, ?float $divergence): array
    {
        $row['eligible'] = false;
        $row['excludeReason'] = $reason;
        $row['confidence'] = $this->confidence($row['source'] === 'CLAUSE' ? 'clause' : 'market', $ext, $growths, $divergence, $certaintyKey);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function finalizeEligibility(array $row): array
    {
        if ($row['confidence']['score'] < (int) config('fantasy.trading.min_confidence_to_recommend')) {
            $row['excludeReason'] = 'LOW_CONFIDENCE';

            return $row;
        }

        $row['eligible'] = true;

        return $row;
    }

    /** @return array<int, int> */
    private function projectedMap(MarketBuyAnalysis $a): array
    {
        return [3 => (int) round($a->projectedValue3d), 7 => (int) round($a->projectedValue7d), 14 => (int) round($a->projectedValue14d)];
    }

    /** @return array<int, int> */
    private function profitMap(MarketBuyAnalysis $a): array
    {
        return [3 => (int) round($a->expectedProfit3d), 7 => (int) round($a->expectedProfit7d), 14 => (int) round($a->expectedProfit14d)];
    }

    /** @return array<int, float> */
    private function roiMap(MarketBuyAnalysis $a): array
    {
        return [3 => $a->expectedROI3d, 7 => $a->expectedROI7d, 14 => $a->expectedROI14d];
    }

    /** @return array<string, ?float> */
    private function growthBlock(MarketBuyAnalysis $a): array
    {
        return ['growth1d' => $a->growth1d, 'growth3d' => $a->growth3d, 'growth7d' => $a->growth7d, 'expectedDailyGrowth' => $a->expectedDailyGrowth];
    }

    /** @return array<string, ?float> */
    private function clauseGrowthBlock(ClauseEconomicAnalysis $a): array
    {
        return ['growth1d' => $a->growth1d, 'growth3d' => $a->growth3d, 'growth7d' => $a->growth7d, 'expectedDailyGrowth' => $a->expectedDailyGrowth];
    }
}
