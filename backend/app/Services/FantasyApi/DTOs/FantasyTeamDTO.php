<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyTeamDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $name,
        public readonly ?string $managerName,
        public readonly ?int $money,
        public readonly ?int $teamValue,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) self::firstString($data, ['id', 'teamId']),
            name: self::firstString($data, ['name', 'teamName']),
            managerName: self::firstString($data, ['managerName', 'manager.managerName', 'manager.name', 'userName']),
            money: self::firstInt($data, ['money', 'teamMoney', 'balance']),
            teamValue: self::firstInt($data, ['teamValue', 'value', 'marketValue']),
            raw: $data,
        );
    }
}
