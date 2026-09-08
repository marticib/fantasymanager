<?php

namespace App\Services\Recommendation\ValueObjects;

/**
 * PlayerDecisionEngine's verdict for one of your own roster players:
 * HOLD / SELL / LOCK_CLAUSE (raise the clause), chosen by comparing
 * independent 0-100 scores rather than reacting to any single signal — see
 * PlayerDecisionEngine's docblock for how each score is built.
 *
 * `sellScore` is the original sell verdict; `adjustedSellScore` folds in the
 * Trade Score bonus (see PlayerDecisionEngine::decide()) and is what
 * actually competes against `holdScore`/`clauseScore` for `action` — both
 * are kept so the UI can show "why" a trading opportunity tipped the
 * balance. `clauseTiming` separates *whether* raising the clause is a good
 * idea from *when* it's safe to actually spend the money on it — see its
 * own keys' meaning in PlayerDecisionEngine::clauseTiming().
 */
class PlayerDecisionResult
{
    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $trade
     * @param  array<string, mixed>  $clauseTiming
     */
    public function __construct(
        public readonly string $action,
        public readonly int $holdScore,
        public readonly int $sellScore,
        public readonly int $adjustedSellScore,
        public readonly int $clauseScore,
        public readonly int $tradeScore,
        public readonly int $confidence,
        public readonly string $reason,
        public readonly ?string $sellReasonCode,
        public readonly array $trade,
        public readonly array $clauseTiming,
        public readonly array $metrics,
    ) {}

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'holdScore' => $this->holdScore,
            'sellScore' => $this->sellScore,
            'adjustedSellScore' => $this->adjustedSellScore,
            'clauseScore' => $this->clauseScore,
            'tradeScore' => $this->tradeScore,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'sellReasonCode' => $this->sellReasonCode,
            'trade' => $this->trade,
            'clauseTiming' => $this->clauseTiming,
            'metrics' => $this->metrics,
        ];
    }
}
