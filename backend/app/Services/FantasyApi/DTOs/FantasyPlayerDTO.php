<?php

namespace App\Services\FantasyApi\DTOs;

class FantasyPlayerDTO extends BaseFantasyDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $name,
        public readonly ?string $nickname,
        public readonly ?string $clubName,
        public readonly ?string $clubExternalId,
        public readonly ?string $position,
        public readonly ?string $imageUrl,
        public readonly ?string $status,
        public readonly ?int $points,
        public readonly ?float $averagePoints,
        public readonly ?int $marketValue,
        public readonly ?string $ownerTeamExternalId,
        public readonly ?int $clauseValue,
        public readonly array $raw,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) self::firstString($data, ['id', 'playerId']),
            name: self::firstString($data, ['name', 'nickname']),
            nickname: self::firstString($data, ['nickname']),
            clubName: self::firstString($data, ['team.name', 'teamName', 'club.name']),
            clubExternalId: self::firstString($data, ['team.id', 'teamId', 'club.id']),
            // positionId first: confirmed live to be a stable numeric code (1..4).
            // The plain "position" field turned out to hold a human, Spanish-
            // language label ("Portero", "Defensa", ...) rather than a slug —
            // kept as a fallback and also handled by normalizePosition().
            position: self::normalizePosition(self::firstString($data, ['positionId', 'position', 'position.name'])),
            imageUrl: self::firstString($data, ['images.transparent.512x512', 'image', 'avatar']),
            status: self::firstString($data, ['playerStatus', 'status']),
            points: self::firstInt($data, ['points', 'totalPoints']),
            averagePoints: self::firstFloat($data, ['averagePoints', 'average']),
            marketValue: self::firstInt($data, ['marketValue', 'value']),
            ownerTeamExternalId: self::firstString($data, ['ownerTeam.id', 'ownerId', 'owner.teamId']),
            clauseValue: self::firstInt($data, ['buyoutClause', 'clauseValue', 'clause']),
            raw: $data,
        );
    }

    /**
     * LaLiga's own payloads have used numeric positionIds (1..4), short slugs
     * ("PT","DF","MC","DL"), and — confirmed live on the /lineup endpoint —
     * full Spanish labels ("Portero","Defensa","Centrocampista","Delantero").
     * Normalize all of them to GK/DF/MF/FW.
     */
    private static function normalizePosition(?string $value): ?string
    {
        return match (mb_strtolower((string) $value)) {
            '1', 'pt', 'gk', 'portero' => 'GK',
            '2', 'df', 'dfc', 'li', 'ld', 'defensa' => 'DF',
            '3', 'mc', 'mf', 'centrocampista' => 'MF',
            '4', 'dl', 'fw', 'delantero' => 'FW',
            default => $value,
        };
    }
}
