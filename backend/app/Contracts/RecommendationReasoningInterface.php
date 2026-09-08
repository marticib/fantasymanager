<?php

namespace App\Contracts;

use App\Services\Recommendation\ValueObjects\RecommendationDraft;

/**
 * Produces the human-readable explanation for an already-decided
 * recommendation. Implementations MUST NOT compute or alter any financial
 * figure (recommended_amount, max_amount, financial_impact) or the action/
 * priority/confidence themselves — those are always decided deterministically
 * by FantasyRecommendationEngine. This interface only turns that decision
 * into prose/bullets.
 *
 * The bundled DeterministicReasoningService formats the engine's own
 * pros/cons. A future LLM-backed implementation (bound in AppServiceProvider
 * instead of the deterministic one) could rephrase or add color commentary,
 * but reads the same immutable RecommendationDraft and still cannot touch
 * the numbers.
 */
interface RecommendationReasoningInterface
{
    /**
     * @return array{reason: string, pros: string[], cons: string[]}
     */
    public function explain(RecommendationDraft $draft): array;
}
