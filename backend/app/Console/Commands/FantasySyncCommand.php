<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FantasySyncCommand extends Command
{
    protected $signature = 'fantasy:sync {--account= : Only sync this fantasy_account id}';

    protected $description = 'Full read-only sync: players, market, team, then regenerate recommendations';

    public function handle(): int
    {
        $accountOption = $this->option('account');
        $args = $accountOption ? ['--account' => $accountOption] : [];

        $this->call('fantasy:sync-players', $args);
        $this->call('fantasy:sync-market', $args);
        $this->call('fantasy:sync-team', $args);
        $this->call('fantasy:generate-recommendations', $args);

        return self::SUCCESS;
    }
}
