<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\Sync\FantasySyncService;
use Illuminate\Console\Command;

class FantasySyncTeamCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:sync-team {--account= : Only sync this fantasy_account id}';

    protected $description = "Sync each account's active team roster, cash and league standing";

    public function handle(FantasySyncService $sync): int
    {
        $accounts = $this->resolveAccounts()->whereNotNull('active_team_id');

        if ($accounts->isEmpty()) {
            $this->warn('No account has an active team selected. Nothing to sync.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $sync->syncTeam($account);
                $this->info("Account #{$account->id}: team synced.");
            } catch (FantasyApiException $e) {
                $failures++;
                $this->error("Account #{$account->id} failed: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
