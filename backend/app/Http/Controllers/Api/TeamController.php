<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyPlayerResource;
use App\Http\Resources\FantasyTeamResource;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyTeam;
use App\Services\Automation\SquadListingService;
use App\Services\FantasyApi\DTOs\FantasyMarketOfferDTO;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\FantasyMarketService;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\PlayerTrendPresenter;
use App\Services\Recommendation\RivalClausePresenter;
use App\Services\Recommendation\TeamValueDeltaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeamController extends Controller
{
    public function show(
        Request $request,
        PlayerTrendPresenter $presenter,
        FantasySettingsService $settings,
        PlayerDecisionEngine $decisionEngine,
        TeamValueDeltaService $teamValueDelta,
    ) {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $rules = $settings->rules($account);
        $cash = (int) ($team->money ?? 0);
        $availableCapital = max(0, $cash - (int) $rules['minimum_cash_reserve']);
        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')->get()->keyBy('fantasy_player_id');
        $decisions = $decisionEngine->evaluateRoster($account);

        $players = $team->teamPlayers()->with('player')->get()
            ->map(function ($tp) use ($account, $externalTrends, $presenter, $decisions) {
                if (! $tp->player) {
                    return null;
                }

                $externalTrend = $externalTrends->get($tp->player->id);
                $p = $presenter->present($tp->player, $account, $externalTrend);
                $decision = $decisions->get($tp->player->id);

                return array_merge(
                    (new FantasyPlayerResource($tp->player))->resolve(),
                    [
                        'clauseValue' => $tp->clause_value,
                        'isLocked' => $tp->is_locked,
                        'isStarter' => $tp->is_starter,
                        'fantasyScore' => $p->score->total,
                        'confidence' => $p->score->confidence,
                        'trend' => $p->trend->toArray(),
                        'externalTrend' => $p->externalTrendPayload(),
                        'history' => $p->history,
                        'historySource' => $p->historySource,
                        // PlayerDecisionEngine's verdict — HOLD/SELL/LOCK_CLAUSE, chosen by
                        // comparing three independent scores rather than reacting to any
                        // single signal. Deliberately not the same as the account's
                        // fantasy_recommendations (dashboard) engine, which never reads
                        // external data; see PlayerDecisionEngine's docblock.
                        'action' => $decision?->action ?? 'HOLD',
                        'decision' => $decision?->toArray(),
                    ],
                );
            })
            ->filter()
            ->values();

        $delta24h = $teamValueDelta->since($team->id, now()->subDay());

        return response()->json([
            'team' => new FantasyTeamResource($team),
            'summary' => [
                'teamValue' => $team->team_value,
                'teamValueDelta24h' => $delta24h['delta'] ?? null,
                'teamValueDelta24hPct' => $delta24h ? round(($delta24h['delta'] / $delta24h['pastTotal']) * 100, 2) : null,
                'cash' => $cash,
                'availableCapital' => $availableCapital,
                'minimumCashReserve' => (int) $rules['minimum_cash_reserve'],
            ],
            'players' => $players,
        ]);
    }

    /**
     * A rival team's full roster, reached from /standings — same per-player
     * shape as show() (value, trend, sparkline, Fantasy Score), but the
     * "what would I do" verdict is RivalClausePresenter's clause analysis,
     * not PlayerDecisionEngine's HOLD/SELL/LOCK_CLAUSE: that engine answers
     * "should I, the owner, hold or sell this player", which doesn't apply
     * to a roster that isn't mine — only its clauses are actionable, exactly
     * like the individual player page's own OWNED_BY_RIVAL context (reused
     * here per player, not re-derived).
     */
    public function rival(Request $request, FantasyTeam $team, PlayerTrendPresenter $presenter, RivalClausePresenter $rivalClausePresenter)
    {
        $account = $this->currentAccount($request);

        if ($team->is_mine || $team->fantasy_league_id !== $account->active_league_id) {
            abort(404);
        }

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')->get()->keyBy('fantasy_player_id');

        $players = $team->teamPlayers()->with('player')->get()
            ->map(function ($tp) use ($account, $externalTrends, $presenter, $rivalClausePresenter) {
                if (! $tp->player) {
                    return null;
                }

                $externalTrend = $externalTrends->get($tp->player->id);
                $p = $presenter->present($tp->player, $account, $externalTrend);
                $decision = $rivalClausePresenter->present($tp->player, $tp, $externalTrend);

                return array_merge(
                    (new FantasyPlayerResource($tp->player))->resolve(),
                    [
                        'clauseValue' => $tp->clause_value,
                        'isLocked' => $tp->is_locked,
                        // The league-scoped roster endpoint rivals are synced through
                        // (FantasyTeamService::getLeagueTeamRoster()) never returns a
                        // starter/bench split, unlike the own-team lineup endpoint —
                        // always null here, never guessed.
                        'isStarter' => null,
                        'fantasyScore' => $p->score->total,
                        'confidence' => $p->score->confidence,
                        'trend' => $p->trend->toArray(),
                        'externalTrend' => $p->externalTrendPayload(),
                        'history' => $p->history,
                        'historySource' => $p->historySource,
                        'action' => $decision['action'] ?? null,
                        'decision' => $decision,
                    ],
                );
            })
            ->filter()
            ->values();

        // fantasy_teams.team_value is only ever cached for the account's own
        // team (FantasySyncService::syncTeam()) — a rival's isn't synced
        // anywhere, so it's derived here with the exact same sum(marketValue)
        // formula that sync uses, rather than left blank.
        $teamValue = $team->team_value ?? $players->sum(fn (array $p) => $p['marketValue'] ?? 0);

        return response()->json([
            'team' => new FantasyTeamResource($team),
            'summary' => [
                'teamValue' => $teamValue,
                // Cash has no data source for a rival at all — LaLiga only
                // exposes the "money" endpoint for the account's own team
                // (see FantasySyncService::syncTeam()) — never guessed.
                'cash' => null,
            ],
            'players' => $players,
        ]);
    }

    public function analysis(Request $request, FantasyScoreService $scoreService)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $players = $team->players()->get();
        $byPosition = $players->groupBy('position');

        $positions = collect(['GK', 'DF', 'MF', 'FW'])->mapWithKeys(function (string $position) use ($byPosition, $account, $scoreService) {
            $group = $byPosition->get($position, collect());
            $scores = $group->map(fn ($p) => $scoreService->compute($p, $account)->total);

            return [$position => [
                'count' => $group->count(),
                'averageScore' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
                'strongCount' => $scores->filter(fn ($s) => $s >= 70)->count(),
            ]];
        });

        $weakPositions = $positions->filter(fn ($p) => $p['strongCount'] < 2)->keys()->values();

        return response()->json([
            'byPosition' => $positions,
            'weakPositions' => $weakPositions,
            'totalPlayers' => $players->count(),
        ]);
    }

    /**
     * Own players currently listed for sale on the market (`FantasyMarketPlayer.seller_team_id`
     * = this team). `askingPrice` is the sale price *you* set when listing the player — not
     * an offer from anyone — so it's surfaced as `salePrice`, never as `marketOffer`. A real
     * offer only exists once another manager actually bids: `numberOfOffers` on the listing
     * says whether that's happened, and only then do we spend a live call on
     * `FantasyMarketService::getDirectOffer()` to find out how much — confirmed live that
     * `numberOfOffers: 0` is the normal case, so this stays cheap. `marketOffer` is `null`
     * until there's a real pending one; the profit/loss column is computed against it, not
     * against `salePrice`, so a listing with no offers yet correctly shows "no verdict" rather
     * than a gain/loss that hasn't actually happened. Each resolved offer is also returned in
     * full under `offers` (id/money/status/expiresAt) so the UI can accept/reject individually
     * — see acceptSaleOffer()/rejectSaleOffer(). `purchasePrice` comes from the league activity
     * feed (FantasySyncService::syncTransactions()) and is `null`, never guessed, for a player
     * acquired before that feed's history (e.g. the initial squad).
     */
    public function sales(Request $request, FantasyMarketService $marketService)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $purchasePrices = $team->teamPlayers()->pluck('purchase_price', 'fantasy_player_id');

        $rows = FantasyMarketPlayer::query()
            ->where('fantasy_league_id', $team->fantasy_league_id)
            ->where('seller_team_id', $team->id)
            ->where('is_on_market', true)
            ->with('player')
            ->get()
            ->filter(fn (FantasyMarketPlayer $listing) => $listing->player)
            ->map(function (FantasyMarketPlayer $listing) use ($purchasePrices, $marketService, $account, $league) {
                $purchasePrice = $purchasePrices->get($listing->fantasy_player_id);
                $numberOfOffers = (int) ($listing->raw_payload['numberOfOffers'] ?? 0);
                $fetch = $numberOfOffers > 0 ? $this->fetchOffers($listing, $marketService, $account, $league) : ['offers' => [], 'failed' => false];
                $pending = collect($fetch['offers'])->filter(fn (FantasyMarketOfferDTO $o) => in_array($o->status, [null, FantasyMarketOfferDTO::STATUS_PENDING], true) && $o->money !== null);
                $marketOffer = $pending->isNotEmpty() ? $pending->max('money') : null;

                $profit = ($purchasePrice !== null && $marketOffer !== null) ? $marketOffer - $purchasePrice : null;
                $profitPct = ($profit !== null && $purchasePrice > 0) ? round(($profit / $purchasePrice) * 100, 1) : null;

                return [
                    'id' => $listing->id,
                    'player' => (new FantasyPlayerResource($listing->player))->resolve(),
                    'marketValue' => $listing->market_value,
                    'salePrice' => $listing->asking_price,
                    'numberOfOffers' => $numberOfOffers,
                    'marketOffer' => $marketOffer,
                    // True when the listing's cached data said there should be
                    // at least one offer but the live lookup to confirm it
                    // failed (typically a stale playerTeamId — see
                    // fetchOffers()) — distinct from numberOfOffers === 0,
                    // which means we successfully confirmed there are none.
                    // The UI must show "couldn't check", never a confident
                    // "N offers" with no amount for this case.
                    'offersCheckFailed' => $numberOfOffers > 0 && $fetch['failed'],
                    'offers' => $pending->map(fn (FantasyMarketOfferDTO $o) => [
                        'id' => $o->id,
                        'money' => $o->money,
                        'expiresAt' => $o->expiresAt,
                    ])->values(),
                    'purchasePrice' => $purchasePrice,
                    'profit' => $profit,
                    'profitPct' => $profitPct,
                    'expiresAt' => $listing->expires_at?->toIso8601String(),
                ];
            })
            ->values();

        $withKnownProfit = $rows->filter(fn ($r) => $r['profit'] !== null);

        return response()->json([
            'players' => $rows,
            'autoListSquad' => (bool) $team->auto_list_squad,
            'summary' => [
                'count' => $rows->count(),
                'withOffers' => $rows->filter(fn ($r) => $r['numberOfOffers'] > 0)->count(),
                // Only the rows with both a known offer and a known purchase_price
                // count toward this — profitKnownCount tells the UI how many of
                // $count that is, so it can caveat a partial total instead of
                // implying it's complete.
                'totalProfit' => $withKnownProfit->isNotEmpty() ? $withKnownProfit->sum('profit') : null,
                'profitKnownCount' => $withKnownProfit->count(),
            ],
        ]);
    }

    /**
     * Toggles `FantasyTeam.auto_list_squad` (see SquadListingService). Turning
     * it on lists every not-yet-listed roster player immediately, in the same
     * request — rather than waiting for the next scheduled fantasy:sync-team
     * run — so the Vendes page reflects it right away.
     */
    public function updateAutoListSquad(Request $request, SquadListingService $squadListingService)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $team->update(['auto_list_squad' => $enabled]);

        $result = ['listed' => 0, 'failed' => 0];
        if ($enabled) {
            $result = $squadListingService->listMissingPlayers($account);
        }

        return response()->json([
            'autoListSquad' => $enabled,
            'listed' => $result['listed'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Accepts one pending offer on one of your own listings. Re-fetches the
     * offer live (rather than trusting any amount the frontend might send)
     * since LaLiga requires the exact current amount back as a confirmation
     * check and the offer could have expired or changed since the page loaded.
     */
    public function acceptSaleOffer(Request $request, FantasyMarketPlayer $marketPlayer, string $offerId, FantasyMarketService $marketService)
    {
        [$account, $league, $marketId, $error] = $this->resolveOwnListing($request, $marketPlayer);

        if ($error) {
            return $error;
        }

        $offer = $this->findOffer($marketPlayer, $offerId, $marketService, $account, $league);

        if (! $offer || $offer->money === null) {
            return response()->json(['message' => 'Aquesta oferta ja no existeix (potser ha caducat o algú altre l\'ha retirada).'], 404);
        }

        try {
            $marketService->acceptOffer($account, $league->external_id, (string) $marketId, $offerId, $offer->money);
        } catch (FantasyApiException $e) {
            return response()->json([
                'message' => 'LaLiga Fantasy ha rebutjat acceptar l\'oferta.',
                'error' => $e->getMessage(),
                'details' => $e->responseBody,
            ], 422);
        }

        return response()->json(['message' => 'Oferta acceptada.', 'amount' => $offer->money]);
    }

    /**
     * Rejects one pending offer on one of your own listings.
     */
    public function rejectSaleOffer(Request $request, FantasyMarketPlayer $marketPlayer, string $offerId, FantasyMarketService $marketService)
    {
        [$account, $league, $marketId, $error] = $this->resolveOwnListing($request, $marketPlayer);

        if ($error) {
            return $error;
        }

        try {
            $marketService->rejectOffer($account, $league->external_id, (string) $marketId, $offerId);
        } catch (FantasyApiException $e) {
            return response()->json([
                'message' => 'LaLiga Fantasy ha rebutjat descartar l\'oferta.',
                'error' => $e->getMessage(),
                'details' => $e->responseBody,
            ], 422);
        }

        return response()->json(['message' => 'Oferta rebutjada.']);
    }

    /**
     * Confirms $marketPlayer is actually one of this account's own listings
     * (never act on an id belonging to another league/account just because
     * it was guessable), and resolves the external ids LaLiga's accept/reject
     * endpoints need. Returns [account, league, marketId, null] on success,
     * or [null, null, null, JsonResponse] to return straight from the caller.
     */
    private function resolveOwnListing(Request $request, FantasyMarketPlayer $marketPlayer): array
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league || $marketPlayer->fantasy_league_id !== $league->id || $marketPlayer->seller_team_id !== $team->id) {
            return [null, null, null, response()->json(['message' => 'Listing not found.'], 404)];
        }

        $marketId = data_get($marketPlayer->raw_payload, 'id');

        if (! $marketId) {
            return [null, null, null, response()->json(['message' => 'Missing market listing id.'], 422)];
        }

        return [$account, $league, $marketId, null];
    }

    private function findOffer(FantasyMarketPlayer $listing, string $offerId, FantasyMarketService $marketService, $account, $league): ?FantasyMarketOfferDTO
    {
        return collect($this->fetchOffers($listing, $marketService, $account, $league)['offers'])
            ->first(fn (FantasyMarketOfferDTO $o) => $o->id === $offerId);
    }

    /**
     * @return FantasyMarketOfferDTO[]
     */
    /**
     * @return array{offers: FantasyMarketOfferDTO[], failed: bool}
     */
    private function fetchOffers(FantasyMarketPlayer $listing, FantasyMarketService $marketService, $account, $league): array
    {
        $playerTeamId = data_get($listing->raw_payload, 'playerTeam.playerTeamId');

        if (! $playerTeamId) {
            return ['offers' => [], 'failed' => true];
        }

        try {
            return ['offers' => $marketService->getDirectOffer($account, $league->external_id, (string) $playerTeamId), 'failed' => false];
        } catch (\Throwable $e) {
            // Confirmed live: this 403s when the listing's cached `playerTeamId`
            // has gone stale relative to the live one — LaLiga regenerates it
            // whenever the player's clause lock/slot rotates (e.g. it unlocks),
            // and our copy is only as fresh as the last fantasy:sync-market run.
            // This is *not* the same as "confirmed zero offers" (an empty
            // array from a successful call) — `failed: true` lets the caller
            // tell "no offers" apart from "couldn't check right now" instead
            // of silently treating a lookup failure as if nothing was there.
            Log::channel('fantasy_api')->warning('fantasy.sales.offer_lookup_failed', [
                'listing_id' => $listing->id,
                'message' => $e->getMessage(),
            ]);

            return ['offers' => [], 'failed' => true];
        }
    }
}
