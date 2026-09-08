<?php

namespace App\Services\FantasyApi\DTOs;

/**
 * Adapts the payload from GET /v4/user/me.
 */
class FantasyUserDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $username,
        public readonly ?string $email,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: self::firstString($data, ['id', 'userId', 'managerId']),
            username: self::firstString($data, ['username', 'managerName', 'displayName', 'name']),
            email: self::firstString($data, ['email']),
            raw: $data,
        );
    }
}
