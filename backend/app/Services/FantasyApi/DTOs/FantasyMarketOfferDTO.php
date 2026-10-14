<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * One pending offer on a player you've listed for sale, from
 * `/league/{id}/playerTeam/{playerTeamId}/offer`. Confirmed live on a real
 * non-empty response (2026-10-14): `{id, money, status, createdAt, updatedAt,
 * isFromMarket, expirationDate}`. `isFromMarket: true` is the market's own
 * standing offer (LaLiga auto-bids close to current market value on listed
 * players that nobody else has bid on yet) — not a competing manager's real
 * offer, even though it goes through the exact same accept/reject flow. The
 * UI must say which kind this is rather than presenting both the same way.
 */
class FantasyMarketOfferDTO extends BaseFantasyDTO
{
    public const STATUS_PENDING = 'pending';

    public function __construct(
        public readonly ?string $id,
        public readonly ?int $money,
        public readonly ?string $status,
        public readonly ?string $expiresAt,
        public readonly ?bool $isFromMarket,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: self::firstString($data, ['id']),
            money: self::firstInt($data, ['money', 'amount']),
            status: self::firstString($data, ['status']),
            expiresAt: self::firstString($data, ['expirationDate', 'expiresAt']),
            isFromMarket: self::firstBool($data, ['isFromMarket']),
            raw: $data,
        );
    }
}
