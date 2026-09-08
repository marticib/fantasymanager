<?php

namespace App\Services\Recommendation\ValueObjects;

use App\Models\FantasyExternalTrend;
use App\Services\Recommendation\PlayerTrendPresenter;

class PlayerTrendPresentation
{
    public function __construct(
        public readonly PlayerTrend $trend,
        public readonly FantasyScoreResult $score,
        public readonly string $effectiveClassification,
        public readonly string $effectiveSource,
        public readonly ?FantasyExternalTrend $externalTrend,
        public readonly array $history,
        public readonly string $historySource,
    ) {}

    public function isRising(): bool
    {
        return in_array($this->effectiveClassification, [PlayerTrend::ALCISTA, PlayerTrend::MOLT_ALCISTA], true);
    }

    public function isFalling(): bool
    {
        return in_array($this->effectiveClassification, [PlayerTrend::BAIXISTA, PlayerTrend::MOLT_BAIXISTA], true);
    }

    public function externalTrendPayload(): ?array
    {
        return PlayerTrendPresenter::externalTrendPayload($this->externalTrend);
    }
}
