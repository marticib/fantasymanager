<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\Sync\FantasySyncService;
use Illuminate\Console\Command;

class FantasySyncMarketCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:sync-market {--account= : Only sync this fantasy_account id}';

    protected $description = "Sync each account's active league market and record a market snapshot";

    public function handle(FantasySyncService $sync): int
    {
        $accounts = $this->resolveAccounts()->whereNotNull('active_league_id');

        if ($accounts->isEmpty()) {
            $this->warn('No account has an active league selected. Nothing to sync.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $count = $sync->syncMarket($account);
                $this->info("Account #{$account->id}: synced {$count} market listings.");
            } catch (FantasyApiException $e) {
                $failures++;
                $this->error("Account #{$account->id} failed: {$e->getMessage()}");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
