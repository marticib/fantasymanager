<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
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

    public function getDirectOffer(FantasyAccount $account, string $leagueId, string $playerTeamId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/league/{$leagueId}/playerTeam/{$playerTeamId}/offer"));

        return $data['data'] ?? $data;
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

    public function sellToMarket(FantasyAccount $account, string $leagueId, string $playerId, int $salePrice): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/market/sell"), [
            'playerId' => $playerId,
            'salePrice' => $salePrice,
        ]);
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
