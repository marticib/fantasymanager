<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyMarketPlayerDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly ?string $marketId,
        public readonly string $playerExternalId,
        public readonly ?string $sellerTeamExternalId,
        public readonly ?int $marketValue,
        public readonly ?int $askingPrice,
        public readonly ?string $expiresAt,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        $playerData = data_get($data, 'playerMaster') ?? data_get($data, 'player') ?? $data;

        return new self(
            marketId: self::firstString($data, ['id', 'marketId']),
            playerExternalId: (string) self::firstString($playerData, ['id', 'playerId']),
            sellerTeamExternalId: self::firstString($data, ['sellerTeam.id', 'teamId', 'ownerId']),
            marketValue: self::firstInt($playerData, ['marketValue', 'value']) ?? self::firstInt($data, ['marketValue']),
            askingPrice: self::firstInt($data, ['salePrice', 'askingPrice', 'price']),
            expiresAt: self::firstString($data, ['expirationDate', 'expiresAt', 'deadline']),
            raw: $data,
        );
    }
}
