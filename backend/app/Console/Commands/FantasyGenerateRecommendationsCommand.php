<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Models\FantasyAccount;
use App\Models\FantasyDailyReport;
use App\Models\FantasyRecommendation;
use App\Services\Recommendation\FantasyRecommendationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class FantasyGenerateRecommendationsCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:generate-recommendations {--account= : Only generate for this fantasy_account id}';

    protected $description = 'Run the recommendation engine and record a daily report snapshot for backtesting';

    public function handle(FantasyRecommendationEngine $engine): int
    {
        $accounts = $this->resolveAccounts()->whereNotNull('active_team_id');

        if ($accounts->isEmpty()) {
            $this->warn('No account has an active team selected. Nothing to generate.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $recommendations = $engine->generate($account);
            $this->info("Account #{$account->id}: {$recommendations->count()} recommendations generated.");

            $this->recordDailyReport($account, $recommendations);
        }

        return self::SUCCESS;
    }

    private function recordDailyReport(FantasyAccount $account, Collection $recommendations): void
    {
        $team = $account->activeTeam;

        $counts = $recommendations->countBy('action');

        $topActions = $recommendations
            ->whereIn('action', [FantasyRecommendation::ACTION_BUY, FantasyRecommendation::ACTION_SELL])
            ->sortBy(fn ($r) => -FantasyRecommendation::priorityWeight($r->priority))
            ->take(3)
            ->map(fn ($r) => [
                'action' => $r->action,
                'player_id' => $r->fantasy_player_id,
                'priority' => $r->priority,
                'confidence' => $r->confidence,
                'reason' => $r->reason,
            ])
            ->values();

        FantasyDailyReport::updateOrCreate(
            ['fantasy_account_id' => $account->id, 'report_date' => now()->toDateString()],
            [
                'summary' => [
                    'teamValue' => $team?->team_value,
                    'cash' => $team?->money,
                    'recommendationCounts' => $counts->toArray(),
                ],
                'top_actions' => $topActions->toArray(),
            ],
        );
    }
}
