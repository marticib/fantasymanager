<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\DTOs\FantasyClubDTO;
use App\Services\FantasyApi\DTOs\FantasyPlayerDTO;

class FantasyPlayerService
{
    public function __construct(private readonly FantasyApiClient $client) {}

    /**
     * The full competition player catalog (all clubs, not just owned/rostered
     * players). This is the master list snapshots and Fantasy Score are
     * computed from.
     *
     * @return FantasyPlayerDTO[]
     */
    public function getAllPlayers(FantasyAccount $account): array
    {
        $data = $this->client->get($account, $this->competitionPath('/players'));
        $list = array_is_list($data) ? $data : ($data['data'] ?? $data['elements'] ?? []);

        return array_map(fn (array $player) => FantasyPlayerDTO::fromArray($player), $list);
    }

    public function getPlayerInLeague(FantasyAccount $account, string $playerId, string $leagueId): FantasyPlayerDTO
    {
        $data = $this->client->get($account, $this->competitionPath("/player/{$playerId}/league/{$leagueId}"));

        return FantasyPlayerDTO::fromArray($data['data'] ?? $data);
    }

    /**
     * The club master list — not competition-scoped (no /v1/competition/{id}
     * prefix, confirmed live), and the only endpoint that maps a club's
     * teamId to an actual name: neither /players nor /teams/{id}/lineup
     * embed one, only a bare numeric teamId.
     *
     * @return FantasyClubDTO[]
     */
    public function getClubs(FantasyAccount $account): array
    {
        $data = $this->client->get($account, '/v3/teams-master');
        $list = array_is_list($data) ? $data : ($data['data'] ?? $data['teams'] ?? []);

        return array_map(fn (array $club) => FantasyClubDTO::fromArray($club), $list);
    }

    public function getCurrentWeek(FantasyAccount $account): ?int
    {
        $data = $this->client->get($account, $this->competitionPath('/week/current'));
        $payload = $data['data'] ?? $data;

        $week = $payload['weekNumber'] ?? $payload['week'] ?? $payload['id'] ?? null;

        return is_numeric($week) ? (int) $week : null;
    }

    private function competitionPath(string $suffix): string
    {
        return '/v1/competition/'.config('fantasy.api.competition_id').$suffix;
    }
}
