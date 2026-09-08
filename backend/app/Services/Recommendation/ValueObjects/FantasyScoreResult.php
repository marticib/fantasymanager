<?php

namespace App\Services\Recommendation\ValueObjects;

class FantasyScoreResult
{
    /**
     * @param  array<string, float>  $breakdown  0-100 sub-score per factor
     * @param  array<string, float>  $weights  the weights (already normalized to sum 100) that were applied
     */
    public function __construct(
        public readonly float $total,
        public readonly array $breakdown,
        public readonly array $weights,
        public readonly int $confidence,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => round($this->total, 1),
            'breakdown' => array_map(fn ($v) => round($v, 1), $this->breakdown),
            'weights' => $this->weights,
            'confidence' => $this->confidence,
        ];
    }
}
