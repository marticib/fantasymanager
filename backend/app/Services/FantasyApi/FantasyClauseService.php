<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;

class FantasyClauseService
{
    public function __construct(private readonly FantasyApiClient $client) {}

    public function checkShield(FantasyAccount $account, string $leagueId, string $playerTeamId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/league/{$leagueId}/player-team/{$playerTeamId}/check-shield"));

        return $data['data'] ?? $data;
    }

    // Write operation — not called automatically, see the note on FantasyMarketService.
    public function payClause(FantasyAccount $account, string $leagueId, string $playerId, int $clauseAmount): array
    {
        return $this->client->post($account, $this->competitionPath("/league/{$leagueId}/buyout/{$playerId}/pay"), [
            'buyoutClauseToPay' => $clauseAmount,
        ]);
    }

    // Write operation — not called automatically, see the note on FantasyMarketService.
    public function increaseClause(FantasyAccount $account, string $leagueId, string $playerId, float $factor, int $valueToIncrease): array
    {
        return $this->client->put($account, $this->competitionPath('/league/'.$leagueId.'/buyout/player'), [
            'playerId' => $playerId,
            'factor' => $factor,
            'valueToIncrease' => $valueToIncrease,
        ]);
    }

    // Write operation ("blindatge") — not called automatically, see the note on FantasyMarketService.
    public function shieldPlayer(FantasyAccount $account, string $leagueId, string $playerId): array
    {
        return $this->client->put($account, $this->competitionPath('/league/'.$leagueId.'/shield/player'), [
            'playerId' => $playerId,
            'rewardedAdType' => 'Blindaje',
            'rewardedAd' => 1,
        ]);
    }

    private function competitionPath(string $suffix): string
    {
        return '/v1/competition/'.config('fantasy.api.competition_id').$suffix;
    }
}
