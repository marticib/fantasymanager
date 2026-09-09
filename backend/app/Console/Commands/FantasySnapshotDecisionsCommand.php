<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Models\FantasyAccount;
use App\Models\FantasyDecisionSnapshot;
use App\Models\FantasyTeamPlayer;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\TodayActionsService;
use App\Services\Recommendation\ValueObjects\PlayerDecisionResult;
use Illuminate\Console\Command;

/**
 * Freezes today's verdict from the app's existing economic engines
 * (PlayerDecisionEngine for the roster, TodayActionsService's already-filtered
 * market/rival-clause opportunities) into fantasy_decision_snapshots — never
 * a second copy of any of their math, just an immutable record of what they
 * said today, for fantasy:evaluate-decisions to grade later.
 *
 * One row per (account, player, action, calendar day): re-running this
 * later the same day refreshes today's own row (same updateOrCreate style
 * as FantasyDailyReport), but never touches a past day's row, and never
 * touches a row that's already been evaluated.
 */
class FantasySnapshotDecisionsCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:snapshot-decisions {--account= : Only snapshot for this fantasy_account id}';

    protected $description = 'Freeze today\'s roster/market/clause economic verdicts for later backtesting';

    public function handle(PlayerDecisionEngine $decisionEngine, TodayActionsService $todayActions): int
    {
        $accounts = $this->resolveAccounts()->whereNotNull('active_team_id');

        if ($accounts->isEmpty()) {
            $this->warn('No account has an active team selected. Nothing to snapshot.');

            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $written = 0;

        foreach ($accounts as $account) {
            $written += $this->snapshotRoster($account, $decisionEngine, $today);
            $written += $this->snapshotMarketAndClauses($account, $todayActions, $today);
        }

        $this->info("{$written} decision snapshot(s) written for {$today}.");

        return self::SUCCESS;
    }

    private function snapshotRoster(FantasyAccount $account, PlayerDecisionEngine $decisionEngine, string $today): int
    {
        $team = $account->activeTeam;

        if (! $team) {
            return 0;
        }

        $teamPlayers = $team->teamPlayers()->get()->keyBy('fantasy_player_id');
        $written = 0;

        foreach ($decisionEngine->evaluateRoster($account) as $playerId => $decision) {
            /** @var PlayerDecisionResult $decision */
            $teamPlayer = $teamPlayers->get($playerId);

            $mainScore = match ($decision->action) {
                'SELL' => $decision->adjustedSellScore,
                'LOCK_CLAUSE' => $decision->clauseScore,
                default => $decision->holdScore,
            };

            [$referenceValue, $status] = $this->rosterReference($decision, $teamPlayer);

            $this->upsert([
                'fantasy_account_id' => $account->id,
                'fantasy_player_id' => $playerId,
                'action' => $decision->action,
                'snapshot_date' => $today,
            ], [
                'fantasy_league_id' => $account->active_league_id,
                'decision_type' => FantasyDecisionSnapshot::TYPE_ROSTER,
                'current_market_value' => $this->roundMoney($decision->metrics['marketValue'] ?? null),
                'reference_value' => $this->roundMoney($referenceValue),
                'projected_value' => $this->roundMoney($decision->trade['projectedValue7d'] ?? null),
                'main_score' => $mainScore,
                'confidence' => $decision->confidence,
                'horizon_days' => config('fantasy.backtest.horizon_days.'.($decision->action === 'LOCK_CLAUSE' ? 'HOLD' : $decision->action), 7),
                'payload' => $decision->toArray(),
                'algorithm_version' => config('fantasy.backtest.algorithm_version'),
                'status' => $status,
                'generated_at' => now(),
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * @return array{0: ?float, 1: string} [referenceValue, initialStatus]
     */
    private function rosterReference(PlayerDecisionResult $decision, ?FantasyTeamPlayer $teamPlayer): array
    {
        if ($decision->action === 'SELL') {
            // Prefer a real offer; a market-value-based backtest is still
            // useful but must never be confused with one anchored to a real
            // offer — see the SELL evaluation for how `outcome` records which.
            $reference = $decision->trade['currentOffer'] ?? $decision->metrics['marketValue'] ?? null;

            return [$reference, FantasyDecisionSnapshot::STATUS_PENDING];
        }

        if ($decision->action === 'LOCK_CLAUSE') {
            // Raising a clause buys protection against a rival stealing the
            // player, not a price move — we have no reliable way to measure
            // "was the player actually about to be stolen", so this is never
            // silently declared correct just because the value went up.
            return [$teamPlayer?->clause_value, FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE];
        }

        // HOLD
        return [$decision->metrics['marketValue'] ?? null, FantasyDecisionSnapshot::STATUS_PENDING];
    }

    private function snapshotMarketAndClauses(FantasyAccount $account, TodayActionsService $todayActions, string $today): int
    {
        if (! $account->activeLeague) {
            return 0;
        }

        $today_ = $todayActions->build($account);
        $written = 0;

        foreach (['urgent', 'opportunity', 'watch'] as $bucket) {
            foreach ($today_['actions'][$bucket] as $action) {
                if (! in_array($action['type'], ['BUY', 'DO_NOT_CHASE', 'PAY_CLAUSE'], true) || ! $action['player']) {
                    continue;
                }

                $type = $action['type'] === 'PAY_CLAUSE' ? FantasyDecisionSnapshot::TYPE_RIVAL_CLAUSE : FantasyDecisionSnapshot::TYPE_MARKET_BUY;

                $reference = match ($action['type']) {
                    'BUY' => is_numeric($action['recommendedBid']) ? $action['recommendedBid'] : $action['maxBid'],
                    'DO_NOT_CHASE' => $action['metadata']['estimatedWinningBid'] ?? $action['maxBid'],
                    default => $action['amount'], // PAY_CLAUSE
                };

                $horizonKey = $action['type'] === 'PAY_CLAUSE' ? 'PAY_CLAUSE' : $action['type'];

                $this->upsert([
                    'fantasy_account_id' => $account->id,
                    'fantasy_player_id' => $action['player']['id'],
                    'action' => $action['type'],
                    'snapshot_date' => $today,
                ], [
                    'fantasy_league_id' => $account->active_league_id,
                    'decision_type' => $type,
                    'current_market_value' => $this->roundMoney($action['currentValue']),
                    'reference_value' => $this->roundMoney($reference),
                    'projected_value' => $this->roundMoney($action['projectedValue']),
                    'main_score' => $action['mainScore'],
                    'confidence' => $action['confidence'],
                    'horizon_days' => config('fantasy.backtest.horizon_days.'.$horizonKey, 14),
                    'payload' => $action,
                    'algorithm_version' => config('fantasy.backtest.algorithm_version'),
                    'status' => FantasyDecisionSnapshot::STATUS_PENDING,
                    'generated_at' => now(),
                ]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $uniqueKeys
     * @param  array<string, mixed>  $attributes
     */
    private function upsert(array $uniqueKeys, array $attributes): void
    {
        $existing = FantasyDecisionSnapshot::where($uniqueKeys)->first();

        // Never touch a row that's already been graded (or deliberately
        // marked non-evaluable) — only today's still-pending row gets
        // refreshed if this command runs more than once today.
        if ($existing && $existing->status !== FantasyDecisionSnapshot::STATUS_PENDING) {
            return;
        }

        FantasyDecisionSnapshot::updateOrCreate($uniqueKeys, $attributes);
    }

    /**
     * The economic engines compute in floats (compound growth math); the
     * money columns here are whole-euro bigints, same convention as every
     * other money column in the schema (fantasy_players.market_value, etc.).
     */
    private function roundMoney(mixed $value): ?int
    {
        return $value !== null ? (int) round((float) $value) : null;
    }
}
