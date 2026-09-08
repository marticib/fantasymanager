<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyClubDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $name,
        public readonly ?string $shortName,
        public readonly ?string $badgeUrl,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) self::firstString($data, ['id', 'teamId']),
            name: self::firstString($data, ['name']),
            shortName: self::firstString($data, ['shortName', 'shortname']),
            badgeUrl: self::firstString($data, ['badgeColor', 'badgeWhite', 'badge']),
            raw: $data,
        );
    }
}
