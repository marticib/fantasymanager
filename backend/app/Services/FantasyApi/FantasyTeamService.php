<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\DTOs\FantasyPlayerDTO;
use App\Services\FantasyApi\DTOs\FantasyRosterEntryDTO;
use App\Services\FantasyApi\DTOs\FantasyTeamDTO;

class FantasyTeamService
{
    public function __construct(private readonly FantasyApiClient $client) {}

    public function getTeam(FantasyAccount $account, string $leagueId, string $teamId): FantasyTeamDTO
    {
        $data = $this->client->get($account, $this->competitionPath("/leagues/{$leagueId}/teams/{$teamId}"));

        return FantasyTeamDTO::fromArray($data['data'] ?? $data);
    }

    public function getMoney(FantasyAccount $account, string $teamId): int
    {
        $data = $this->client->get($account, $this->competitionPath("/teams/{$teamId}/money"));
        $payload = $data['data'] ?? $data;

        return (int) ($payload['money'] ?? $payload['teamMoney'] ?? $payload['balance'] ?? 0);
    }

    /**
     * Current squad. The real payload nests the roster under
     * `formation.{goalkeeper,defender,midfield,striker,bench}[]`, each entry
     * holding the player under `playerMaster` and the buyout clause as a
     * sibling field `buyoutClause` (not inside playerMaster) — confirmed
     * against a live response, not guessed. `formation.coach` is not a
     * scoreable player and is intentionally skipped.
     *
     * @return FantasyRosterEntryDTO[]
     */
    public function getLineup(FantasyAccount $account, string $teamId, ?int $week = null): array
    {
        $path = $week
            ? $this->competitionPath("/teams/{$teamId}/lineup/week/{$week}")
            : $this->competitionPath("/teams/{$teamId}/lineup");

        $data = $this->client->get($account, $path);
        $payload = $data['data'] ?? $data;
        $formation = $payload['formation'] ?? [];

        $slots = [];
        foreach (['goalkeeper', 'defender', 'midfield', 'striker'] as $group) {
            foreach ((array) ($formation[$group] ?? []) as $slot) {
                $slots[] = [$slot, true];
            }
        }
        foreach ((array) ($formation['bench'] ?? []) as $slot) {
            $slots[] = [$slot, false];
        }

        $entries = [];
        foreach ($slots as [$slot, $isStarter]) {
            $playerData = $slot['playerMaster'] ?? null;

            if (! is_array($playerData)) {
                continue;
            }

            if (isset($slot['buyoutClause'])) {
                $playerData['buyoutClause'] = $slot['buyoutClause'];
            }

            $entries[] = new FantasyRosterEntryDTO(
                FantasyPlayerDTO::fromArray($playerData),
                $isStarter,
                playerTeamId: $slot['playerTeamId'] ?? null,
            );
        }

        return $entries;
    }

    /**
     * A team's full squad as seen from within a league, buyout clauses
     * included — the only way to read another manager's roster. The direct
     * `/teams/{id}/lineup` endpoint used by getLineup() only works for your
     * own team; it 403s for anyone else's (confirmed live). This endpoint
     * returns a flat `players[]` list (no starter/bench formation split),
     * each entry holding `playerMaster` plus sibling `buyoutClause` /
     * `buyoutClauseLockedEndTime` / `isShielded`, same as getLineup()'s
     * per-slot shape.
     *
     * @return FantasyRosterEntryDTO[]
     */
    public function getLeagueTeamRoster(FantasyAccount $account, string $leagueId, string $teamId): array
    {
        $data = $this->client->get($account, $this->competitionPath("/leagues/{$leagueId}/teams/{$teamId}"));
        $players = $data['players'] ?? [];

        $entries = [];
        foreach ($players as $slot) {
            $playerData = $slot['playerMaster'] ?? null;

            if (! is_array($playerData)) {
                continue;
            }

            if (isset($slot['buyoutClause'])) {
                $playerData['buyoutClause'] = $slot['buyoutClause'];
            }

            $entries[] = new FantasyRosterEntryDTO(
                FantasyPlayerDTO::fromArray($playerData),
                null,
                clauseLockedUntil: $slot['buyoutClauseLockedEndTime'] ?? null,
                isShielded: $slot['isShielded'] ?? null,
                playerTeamId: $slot['playerTeamId'] ?? null,
            );
        }

        return $entries;
    }

    private function competitionPath(string $suffix): string
    {
        return '/v1/competition/'.config('fantasy.api.competition_id').$suffix;
    }
}
