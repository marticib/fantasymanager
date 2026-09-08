<?php

namespace App\Services\Recommendation;

use App\Contracts\RecommendationReasoningInterface;
use App\Services\Recommendation\ValueObjects\RecommendationDraft;

/**
 * Default, always-available implementation: the explanation is exactly the
 * reason/pros/cons the deterministic engine already derived from real
 * numbers. No network calls, no LLM, nothing that can hallucinate a figure.
 */
class DeterministicReasoningService implements RecommendationReasoningInterface
{
    public function explain(RecommendationDraft $draft): array
    {
        return [
            'reason' => $draft->reason,
            'pros' => $draft->pros,
            'cons' => $draft->cons,
        ];
    }
}
