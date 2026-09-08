<?php

namespace App\Services\ExternalData\DTOs;

/**
 * One player row parsed from futbolfantasy.com's market analytics page. All
 * numeric fields come straight from the page's own `data-*` attributes — it
 * has already computed the percentage deltas for us, so we don't re-derive
 * them from raw values (fewer places to get the math wrong).
 */
class FutbolFantasyRowDTO
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $rawName,
        public readonly ?string $position,
        public readonly ?string $clubName,
        public readonly ?int $valueNow,
        public readonly ?int $value1d,
        public readonly ?int $value3d,
        public readonly ?int $value7d,
        public readonly ?int $value14d,
        public readonly ?int $value30d,
        public readonly ?float $pct1d,
        public readonly ?float $pct2d,
        public readonly ?float $pct3d,
        public readonly ?float $pct7d,
        public readonly ?float $pct14d,
        public readonly ?float $pct30d,
        public readonly ?int $trendDays,
        public readonly ?bool $decelerating,
    ) {}

    public static function fromAttributes(array $attrs, ?string $displayName = null, ?string $clubName = null, ?bool $decelerating = null): ?self
    {
        if (empty($attrs['id']) || empty($attrs['nombre'])) {
            return null;
        }

        return new self(
            externalId: $attrs['id'],
            rawName: $displayName ?: $attrs['nombre'],
            position: $attrs['posicion'] ?? null,
            clubName: $clubName,
            valueNow: self::toInt($attrs['valor'] ?? null),
            // The value the player was actually worth N days ago — lets us
            // draw a real sparkline even before our own snapshot history has
            // accumulated (fantasy_player_snapshots only starts the day the
            // account is connected).
            value1d: self::toInt($attrs['valor1'] ?? null),
            value3d: self::toInt($attrs['valor3'] ?? null),
            value7d: self::toInt($attrs['valor7'] ?? null),
            value14d: self::toInt($attrs['valor14'] ?? null),
            value30d: self::toInt($attrs['valor30'] ?? null),
            pct1d: self::toFloat($attrs['diferencia-pct1'] ?? null),
            pct2d: self::toFloat($attrs['diferencia-pct2'] ?? null),
            pct3d: self::toFloat($attrs['diferencia-pct3'] ?? null),
            pct7d: self::toFloat($attrs['diferencia-pct7'] ?? null),
            pct14d: self::toFloat($attrs['diferencia-pct14'] ?? null),
            pct30d: self::toFloat($attrs['diferencia-pct30'] ?? null),
            trendDays: self::toInt($attrs['tendencia'] ?? null),
            decelerating: $decelerating,
        );
    }

    private static function toInt(?string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function toFloat(?string $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
