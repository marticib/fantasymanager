<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Models\FantasyClause;
use App\Models\FantasyTeamPlayer;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\Recommendation\ClauseOpportunityService;
use App\Services\Sync\FantasySyncService;
use Illuminate\Console\Command;

class FantasySyncClausesCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:sync-clauses {--account= : Only sync this fantasy_account id}';

    protected $description = "Sync each league's rival rosters (for their buyout clauses) and record a Clause Opportunity Score snapshot for each";

    public function handle(FantasySyncService $sync, ClauseOpportunityService $opportunities): int
    {
        $accounts = $this->resolveAccounts()->whereNotNull('active_team_id')->whereNotNull('active_league_id');

        if ($accounts->isEmpty()) {
            $this->warn('No account has an active team and league selected. Nothing to sync.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $result = $sync->syncRivalRosters($account);
                $this->info("Account #{$account->id}: {$result['teams']} rival teams, {$result['players']} players synced.");

                $capturedAt = now();
                $evaluations = $opportunities->evaluate($account);

                foreach ($evaluations as $row) {
                    $teamPlayer = FantasyTeamPlayer::where('fantasy_team_id', $row['ownerTeamId'])
                        ->where('fantasy_player_id', $row['playerId'])
                        ->first();

                    if (! $teamPlayer) {
                        continue;
                    }

                    FantasyClause::create([
                        'fantasy_team_player_id' => $teamPlayer->id,
                        'clause_value' => $row['clauseValue'],
                        'opportunity_score' => $row['clauseEconomicScore'],
                        'is_recommended' => $row['economicRecommendation'] === 'PAY_CLAUSE',
                        'analysis' => $row,
                        'captured_at' => $capturedAt,
                    ]);
                }

                $this->info("Account #{$account->id}: {$evaluations->count()} clause opportunities scored.");
            } catch (FantasyApiException $e) {
                $failures++;
                $this->error("Account #{$account->id} failed: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
