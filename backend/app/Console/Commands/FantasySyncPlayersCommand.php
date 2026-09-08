<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\Sync\FantasySyncService;
use Illuminate\Console\Command;

class FantasySyncPlayersCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:sync-players {--account= : Only sync using this fantasy_account id}';

    protected $description = 'Sync the full LaLiga Fantasy player catalog and record value/points snapshots';

    public function handle(FantasySyncService $sync): int
    {
        $accounts = $this->resolveAccounts();

        if ($accounts->isEmpty()) {
            $this->warn('No LaLiga Fantasy account with tokens configured. Nothing to sync.');

            return self::SUCCESS;
        }

        // The player catalog is shared across accounts/leagues, so one
        // successful sync is enough — but we still try each account in turn
        // in case the first one's tokens are stale.
        foreach ($accounts as $account) {
            try {
                $count = $sync->syncPlayers($account);
                $this->info("Synced {$count} players using account #{$account->id}.");

                return self::SUCCESS;
            } catch (FantasyApiException $e) {
                $this->error("Account #{$account->id} failed: {$e->getMessage()}");
            }
        }

        return self::FAILURE;
    }
}
