<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * One pending offer on a player you've listed for sale, from
 * `/league/{id}/playerTeam/{playerTeamId}/offer`. This account has never had
 * a real offer to observe directly, so these field names are cross-checked
 * instead against a community-maintained client hitting the same LaLiga
 * Fantasy API (Externoak/LaLigaApp, which also calls `/offer/{id}/accept`
 * and `/offer/{id}/reject` the same way FantasyMarketService does) —
 * `id`, `money` and `status` ("pending" while actionable) all confirmed
 * there, not guessed from scratch.
 */
class FantasyMarketOfferDTO extends BaseFantasyDTO
{
    public const STATUS_PENDING = 'pending';

    public function __construct(
        public readonly ?string $id,
        public readonly ?int $money,
        public readonly ?string $status,
        public readonly ?string $expiresAt,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: self::firstString($data, ['id']),
            money: self::firstInt($data, ['money', 'amount']),
            status: self::firstString($data, ['status']),
            expiresAt: self::firstString($data, ['expirationDate', 'expiresAt']),
            raw: $data,
        );
    }
}
