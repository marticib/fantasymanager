<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\DTOs\FantasyLeagueDTO;
use App\Services\FantasyApi\DTOs\FantasyStandingDTO;
use App\Services\FantasyApi\DTOs\FantasyUserDTO;

class FantasyLeagueService
{
    public function __construct(private readonly FantasyApiClient $client) {}

    public function getCurrentUser(FantasyAccount $account): FantasyUserDTO
    {
        $data = $this->client->get($account, '/v4/user/me');

        return FantasyUserDTO::fromArray($data['data'] ?? $data);
    }

    /**
     * @return FantasyLeagueDTO[]
     */
    public function getLeagues(FantasyAccount $account): array
    {
        $data = $this->client->get($account, $this->competitionPath('/leagues'));

        return array_map(
            fn (array $league) => FantasyLeagueDTO::fromArray($league),
            $this->unwrapList($data),
        );
    }

    /**
     * @return FantasyStandingDTO[]
     */
    public function getStanding(FantasyAccount $account, string $leagueId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/leagues/{$leagueId}/standing"));

        return array_map(
            fn (array $row) => FantasyStandingDTO::fromArray($row),
            $this->unwrapList($data),
        );
    }

    public function getActivity(FantasyAccount $account, string $leagueId, int $index = 0): array
    {
        $data = $this->client->get($account, $this->competitionPath("/leagues/{$leagueId}/activity/{$index}"));

        return $this->unwrapList($data);
    }

    private function competitionPath(string $suffix): string
    {
        return '/v1/competition/'.config('fantasy.api.competition_id').$suffix;
    }

    /**
     * LaLiga's list endpoints have shipped as bare arrays and as
     * {"data":[...]} envelopes depending on season; support both.
     */
    private function unwrapList(array $data): array
    {
        if (array_is_list($data)) {
            return $data;
        }

        return $data['data'] ?? $data['elements'] ?? [];
    }
}
