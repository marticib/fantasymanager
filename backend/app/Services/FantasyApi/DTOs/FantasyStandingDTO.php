<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyStandingDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly string $teamExternalId,
        public readonly ?string $teamName,
        public readonly ?int $position,
        public readonly ?int $points,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        $teamData = data_get($data, 'team') ?? $data;

        return new self(
            teamExternalId: (string) self::firstString($teamData, ['id', 'teamId']),
            // Confirmed live: the standing payload's team object has no plain
            // "name" — managers are identified by their manager name, same as
            // FantasyTeamDTO's own fallback chain.
            teamName: self::firstString($teamData, ['name', 'manager.managerName', 'manager.name', 'managerName']),
            position: self::firstInt($data, ['position', 'rank']),
            points: self::firstInt($data, ['points', 'totalPoints']),
            raw: $data,
        );
    }
}
