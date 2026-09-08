<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyLeagueDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $name,
        public readonly ?string $mode,
        public readonly ?int $teamCount,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) self::firstString($data, ['id', 'leagueId']),
            name: self::firstString($data, ['name', 'leagueName']),
            mode: self::firstString($data, ['mode', 'type', 'gameMode']),
            teamCount: self::firstInt($data, ['teamCount', 'numberOfTeams', 'teamsCount']),
            raw: $data,
        );
    }
}
