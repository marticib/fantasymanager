<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\DTOs\FantasyMarketOfferDTO;
use App\Services\FantasyApi\DTOs\FantasyMarketPlayerDTO;

class FantasyMarketService
{
    public function __construct(private readonly FantasyApiClient $client) {}

    /**
     * @return FantasyMarketPlayerDTO[]
     */
    public function getMarket(FantasyAccount $account, string $leagueId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/league/{$leagueId}/market"));
        $list = array_is_list($data) ? $data : ($data['data'] ?? $data['elements'] ?? []);

        return array_map(fn (array $row) => FantasyMarketPlayerDTO::fromArray($row), $list);
    }

    /**
     * Pending direct offers on one of your own listed players, keyed by
     * `playerTeamId` (the roster-slot id — see FantasyRosterEntryDTO.
     * `numberOfOffers` on the listing itself tells you whether it's worth
     * calling this at all).
     *
     * @return FantasyMarketOfferDTO[]
     */
    public function getDirectOffer(FantasyAccount $account, string $leagueId, string $playerTeamId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/league/{$leagueId}/playerTeam/{$playerTeamId}/offer"));
        $list = $data['data'] ?? $data;
        $list = array_is_list($list) ? $list : [$list];

        return array_map(
            fn (array $row) => FantasyMarketOfferDTO::fromArray($row),
            array_values(array_filter($list, 'is_array')),
        );
    }

    /**
     * Accepts a pending offer on one of your own listed players (TeamController::acceptSaleOffer).
     * LaLiga requires the amount back in the body as a confirmation check —
     * a missing or mismatched `offerMoney` is rejected with 400 (confirmed
     * against the same community client as FantasyMarketOfferDTO). Callers
     * should re-fetch getDirectOffer() right before calling this rather than
     * trusting a value the frontend echoed back, since the offer could have
     * expired or changed in between.
     */
    public function acceptOffer(FantasyAccount $account, string $leagueId, string $marketId, string $offerId, int $offerMoney): array
    {
        return $this->client->post(
            $account,
            $this->competitionPath("/league/{$leagueId}/market/{$marketId}/offer/{$offerId}/accept"),
            ['offerMoney' => $offerMoney],
        );
    }

    /**
     * Rejects a pending offer on one of your own listed players (TeamController::rejectSaleOffer).
     */
    public function rejectOffer(FantasyAccount $account, string $leagueId, string $marketId, string $offerId): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/market/{$marketId}/offer/{$offerId}/reject"));
    }

    /**
     * Lists a player for sale at `salePrice` (SquadListingService — backs
     * `FantasyTeam.auto_list_squad`). Shape confirmed the same way as
     * accept/rejectOffer(): cross-checked against a community client hitting
     * this same API, not our own prior traffic — including, the hard way,
     * that despite the body field being named `playerId`, the value it
     * actually wants is the *roster-slot* id (`FantasyTeamPlayer.player_team_id`,
     * same id FantasyRosterEntryDTO calls `playerTeamId`), not the player's
     * own global id — sending the latter gets a 400 for every player, with a
     * misleading "player is not in your team anymore" message.
     */
    public function sellToMarket(FantasyAccount $account, string $leagueId, string $playerTeamId, int $salePrice): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/market/sell"), [
            'playerId' => $playerTeamId,
            'salePrice' => $salePrice,
        ]);
    }

    // --- The following are write operations. They are intentionally left as
    // thin, explicit wrappers (never called automatically by the sync/recommendation
    // pipeline) so a future "act on this recommendation" button has a safe,
    // already-abstracted place to call into — but nothing in this codebase
    // invokes them yet. The app is read-only until a human wires up a UI
    // action to one of these. ---

    public function bid(FantasyAccount $account, string $leagueId, string $marketId, int $amount): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/market/{$marketId}/bid"), ['money' => $amount]);
    }

    public function cancelBid(FantasyAccount $account, string $leagueId, string $marketId, string $bidId): array
    {
        return $this->client->delete($account, $this->competitionPath("/league/{$leagueId}/market/{$marketId}/bid/{$bidId}/cancel"));
    }

    public function withdrawFromMarket(FantasyAccount $account, string $leagueId, string $marketId): array
    {
        return $this->client->delete($account, $this->competitionPath("/league/{$leagueId}/market/{$marketId}/delete"));
    }

    public function makeDirectOffer(FantasyAccount $account, string $leagueId, string $playerId, int $amount): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/market/direct-offer"), [
            'playerId' => $playerId,
            'money' => $amount,
        ]);
    }

    private function competitionPath(string $suffix): string
    {
        return '/v1/competition/'.config('fantasy.api.competition_id').$suffix;
    }
}
