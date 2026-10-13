<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * One row from the league activity feed (`/leagues/{id}/activity/{index}`).
 * LaLiga doesn't document `activityTypeId` anywhere; only type 1 has been
 * confirmed live, by cross-checking a real league's feed against its own
 * roster data — a player changing hands for money, where `buyerExternalId`
 * (`user1Id`) ends up owning the player and `amount` is exactly what they
 * paid (it matched the resulting `clause_value` on their roster in every
 * case checked, for both clause payments and market purchases — LaLiga
 * logs both the same way). Other type ids seen in the feed (daily value
 * ticks, league-join events, weekly bonuses, ...) are left unmapped since
 * their meaning has never been confirmed; `raw` keeps the original row so
 * nothing is lost if that changes.
 */
class FantasyActivityDTO extends BaseFantasyDTO
{
    public const TYPE_OWNERSHIP_TRANSFER = 1;

    public function __construct(
        public readonly ?string $externalId,
        public readonly ?int $activityTypeId,
        public readonly ?string $buyerExternalId,
        public readonly ?string $sellerExternalId,
        public readonly ?string $playerExternalId,
        public readonly ?int $amount,
        public readonly ?string $occurredAt,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: self::firstString($data, ['id']),
            activityTypeId: self::firstInt($data, ['activityTypeId']),
            buyerExternalId: self::firstString($data, ['user1Id']),
            sellerExternalId: self::firstString($data, ['user2Id']),
            playerExternalId: self::firstString($data, ['playerMasterId']),
            amount: self::firstInt($data, ['amount']),
            occurredAt: self::firstString($data, ['createdAt']),
            raw: $data,
        );
    }
}
