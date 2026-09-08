<?php

namespace App\Services\Recommendation\ValueObjects;

use Illuminate\Support\Carbon;

class RecommendationDraft
{
    /**
     * @param  string[]  $pros
     * @param  string[]  $cons
     */
    public function __construct(
        public readonly string $action,
        public readonly int $fantasyPlayerId,
        public readonly string $priority,
        public readonly int $confidence,
        public readonly string $reason,
        public readonly array $pros,
        public readonly array $cons,
        public readonly ?int $financialImpact,
        public readonly ?int $recommendedAmount,
        public readonly ?int $maxAmount,
        public readonly ?float $fantasyScore,
        public readonly ?Carbon $expiresAt,
    ) {}
}
