<?php

namespace App\Console\Commands;

use App\Models\FantasyDecisionSnapshot;
use App\Models\FantasyPlayerSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Grades PENDING fantasy_decision_snapshots whose evaluation horizon has
 * arrived, against what actually happened — never against today's
 * algorithm or today's config. Everything it reads to grade a decision
 * comes from that decision's own frozen `payload`/`reference_value`/
 * `projected_value` (set the day it was made) plus one real snapshot at or
 * after the horizon date — never a snapshot from before the decision was
 * made (no look-ahead) and never a value the algorithm didn't actually use.
 */
class FantasyEvaluateDecisionsCommand extends Command
{
    protected $signature = 'fantasy:evaluate-decisions';

    protected $description = 'Grade decision snapshots whose evaluation horizon has arrived against real outcomes';

    public function handle(): int
    {
        $toleranceDays = (int) config('fantasy.backtest.evaluation_tolerance_days');
        $pending = FantasyDecisionSnapshot::where('status', FantasyDecisionSnapshot::STATUS_PENDING)->get();

        $evaluated = 0;
        $insufficient = 0;
        $skipped = 0;

        foreach ($pending as $snapshot) {
            $horizonDate = $snapshot->snapshot_date->copy()->addDays($snapshot->horizon_days);

            if ($horizonDate->isFuture()) {
                $skipped++;

                continue;
            }

            $actualValue = $this->actualValueNear($snapshot->fantasy_player_id, $horizonDate, $toleranceDays);

            if ($actualValue === null) {
                $snapshot->update(['status' => FantasyDecisionSnapshot::STATUS_INSUFFICIENT_DATA, 'evaluated_at' => now()]);
                $insufficient++;

                continue;
            }

            $snapshot->update([
                'status' => FantasyDecisionSnapshot::STATUS_EVALUATED,
                'outcome' => $this->evaluate($snapshot, (float) $actualValue),
                'evaluated_at' => now(),
            ]);
            $evaluated++;
        }

        $this->info("{$evaluated} evaluated, {$insufficient} insufficient data, {$skipped} not due yet.");

        return self::SUCCESS;
    }

    /**
     * The nearest real snapshot AT OR AFTER the horizon date, within
     * tolerance — never before it (a decision from day X can only ever be
     * graded using data that came after its own horizon, not sooner) and
     * never invented when nothing real is close enough.
     */
    private function actualValueNear(int $playerId, Carbon $horizonDate, int $toleranceDays): ?int
    {
        $snapshot = FantasyPlayerSnapshot::where('fantasy_player_id', $playerId)
            ->whereNotNull('market_value')
            ->where('captured_at', '>=', $horizonDate)
            ->where('captured_at', '<=', $horizonDate->copy()->addDays($toleranceDays))
            ->orderBy('captured_at')
            ->first();

        return $snapshot?->market_value;
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(FantasyDecisionSnapshot $snapshot, float $actualValue): array
    {
        $reference = $snapshot->reference_value !== null ? (float) $snapshot->reference_value : null;
        $predicted = $snapshot->projected_value !== null ? (float) $snapshot->projected_value : null;

        $predictionError = $predicted !== null ? $actualValue - $predicted : null;
        $predictionErrorPct = ($predicted !== null && $predicted > 0) ? $predictionError / $predicted : null;

        $base = [
            'actualValue' => $actualValue,
            'referenceValue' => $reference,
            'predictedValue' => $predicted,
            'predictionError' => $predictionError,
            'predictionErrorPct' => $predictionErrorPct,
        ];

        return match ($snapshot->action) {
            'BUY', 'PAY_CLAUSE' => array_merge($base, $this->buyLikeOutcome($reference, $actualValue)),
            'DO_NOT_CHASE' => array_merge($base, $this->doNotChaseOutcome($reference, $actualValue)),
            'SELL' => array_merge($base, $this->sellOutcome($reference, $actualValue)),
            'HOLD' => array_merge($base, $this->holdOutcome($reference, $actualValue)),
            default => array_merge($base, ['favorable' => null, 'note' => 'No evaluation methodology for this action.']),
        };
    }

    /**
     * Shared by BUY (acquisitionPrice) and PAY_CLAUSE (clauseValue) — same
     * "would this have paid for itself" question, same formula, spec
     * section 17 explicitly says PAY_CLAUSE follows BUY's philosophy.
     * Threshold: break-even (realProfit > 0) — the deliberately simple bar
     * from the spec's own worked examples, not the algorithm's requiredROI
     * config (which can change over time and must never retroactively
     * regrade an old decision).
     */
    private function buyLikeOutcome(?float $reference, float $actualValue): array
    {
        if ($reference === null || $reference <= 0) {
            return ['favorable' => null, 'note' => 'No reference price was recorded for this decision.'];
        }

        $realProfit = $actualValue - $reference;
        $realRoi = $realProfit / $reference;

        return [
            'realProfit' => $realProfit,
            'realRoi' => $realRoi,
            'favorable' => $realProfit > 0,
            'threshold' => 'break-even (0% ROI)',
        ];
    }

    /**
     * DO_NOT_CHASE: was avoiding the estimated winning bid the right call?
     * Favorable when the player ended up worth less than what we would
     * have had to pay to win the auction.
     */
    private function doNotChaseOutcome(?float $estimatedWinningBid, float $actualValue): array
    {
        if ($estimatedWinningBid === null) {
            return ['favorable' => null, 'note' => 'No estimated winning bid was recorded for this decision.'];
        }

        $avoidedLoss = $estimatedWinningBid - $actualValue;

        return [
            'avoidedLoss' => $avoidedLoss,
            'favorable' => $avoidedLoss > 0,
        ];
    }

    /**
     * SELL: avoidedLoss = sellReference - actualValue. Positive means the
     * price fell after we (would have) sold — a good call. Negative is an
     * opportunity loss — the same number, just framed the other way round.
     */
    private function sellOutcome(?float $sellReference, float $actualValue): array
    {
        if ($sellReference === null) {
            return ['favorable' => null, 'note' => 'No sell reference price was recorded for this decision.'];
        }

        $avoidedLoss = $sellReference - $actualValue;

        return [
            'avoidedLoss' => $avoidedLoss,
            'opportunityLoss' => -$avoidedLoss,
            'favorable' => $avoidedLoss > 0,
        ];
    }

    /**
     * HOLD: a deliberately simple first-pass approximation (spec section 16
     * asks for exactly this, and to document it as such) — favorable if the
     * player didn't lose value. This does NOT compare against the best
     * alternative decision available at the time, only against "did nothing
     * beat doing nothing".
     */
    private function holdOutcome(?float $marketValueAtSnapshot, float $actualValue): array
    {
        if ($marketValueAtSnapshot === null) {
            return ['favorable' => null, 'note' => 'No market value was recorded for this decision.'];
        }

        return [
            'valueChange' => $actualValue - $marketValueAtSnapshot,
            'favorable' => $actualValue >= $marketValueAtSnapshot,
            'methodology' => 'approximation: favorable if value did not fall — not compared against the best alternative decision',
        ];
    }
}
