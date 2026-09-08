<?php

namespace App\Console\Commands;

use App\Services\ExternalData\FutbolFantasySyncService;
use Illuminate\Console\Command;

class FantasySyncExternalTrendsCommand extends Command
{
    protected $signature = 'fantasy:sync-external-trends';

    protected $description = 'Scrape futbolfantasy.com for supplementary (unofficial) market value trends';

    public function handle(FutbolFantasySyncService $sync): int
    {
        if (! config('fantasy.external.enabled')) {
            $this->warn('External trends are disabled (FANTASY_EXTERNAL_TRENDS_ENABLED=false). Skipping.');

            return self::SUCCESS;
        }

        $result = $sync->sync();

        if ($result['total'] === 0) {
            $this->warn('Got 0 rows from futbolfantasy.com — the page may be unreachable or its markup may have changed.');

            return self::SUCCESS;
        }

        $this->info("Matched {$result['matched']}/{$result['total']} players ({$result['unmatched']} unmatched).");

        return self::SUCCESS;
    }
}
