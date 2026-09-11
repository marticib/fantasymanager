<?php

namespace App\Services\Recommendation\ValueObjects;

class PlayerTrend
{
    public const MOLT_ALCISTA = 'MOLT_ALCISTA';

    public const ALCISTA = 'ALCISTA';

    public const ESTABLE = 'ESTABLE';

    public const BAIXISTA = 'BAIXISTA';

    public const MOLT_BAIXISTA = 'MOLT_BAIXISTA';

    public function __construct(
        public readonly ?int $currentValue,
        public readonly ?int $change24h,
        public readonly ?int $change3d,
        public readonly ?int $change7d,
        public readonly ?float $pctChange24h,
        public readonly ?float $pctChange3d,
        public readonly ?float $pctChange7d,
        public readonly ?int $acceleration,
        public readonly string $classification,
        public readonly int $snapshotCount,
    ) {}

    public function hasEnoughData(): bool
    {
        return $this->snapshotCount >= 2;
    }

    public function isRising(): bool
    {
        return in_array($this->classification, [self::ALCISTA, self::MOLT_ALCISTA], true);
    }

    public function isFalling(): bool
    {
        return in_array($this->classification, [self::BAIXISTA, self::MOLT_BAIXISTA], true);
    }

    public function label(): string
    {
        return match ($this->classification) {
            self::MOLT_ALCISTA => 'MOLT ALCISTA',
            self::ALCISTA => 'ALCISTA',
            self::ESTABLE => 'ESTABLE',
            self::BAIXISTA => 'BAIXISTA',
            self::MOLT_BAIXISTA => 'MOLT BAIXISTA',
            default => 'DESCONEGUDA',
        };
    }

    public function toArray(): array
    {
        return [
            'currentValue' => $this->currentValue,
            'change24h' => $this->change24h,
            'change3d' => $this->change3d,
            'change7d' => $this->change7d,
            'pctChange24h' => $this->pctChange24h,
            'pctChange3d' => $this->pctChange3d,
            'pctChange7d' => $this->pctChange7d,
            'acceleration' => $this->acceleration,
            'classification' => $this->classification,
            'classificationLabel' => $this->label(),
            'snapshotCount' => $this->snapshotCount,
        ];
    }
}
