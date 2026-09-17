<?php

use App\Console\CronFrequency;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Fantasy sync schedule
|--------------------------------------------------------------------------
|
| Frequencies come from config/fantasy.php (FANTASY_SYNC_*_FREQUENCY env
| vars) so cadence can be tuned per deployment without touching this file.
| Every job is withoutOverlapping (a slow LaLiga response should never stack
| runs) and onOneServer (safe to run more than one queue worker/box).
|
*/

Schedule::command('fantasy:sync-players')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.players_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:sync-market')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.market_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:sync-team')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.team_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:generate-recommendations')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.recommendations_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

// Rival rosters change far less often than the market, and this walks every
// other team in the league (one API call each), so it gets its own slower
// cadence rather than piggybacking on fantasy:sync-team.
Schedule::command('fantasy:sync-clauses')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.clauses_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

// Deliberately its own, much tighter cadence than fantasy:sync-clauses
// above — this is the only command that ever spends real in-game money
// automatically (ClausePurchaseOrderService), so it always re-checks live
// rather than trusting that slower snapshot.
Schedule::command('fantasy:process-clause-orders')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.clause_orders_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

if (config('fantasy.external.enabled')) {
    Schedule::command('fantasy:sync-external-trends')
        ->cron(CronFrequency::everyMinutes((int) config('fantasy.external.sync_frequency_minutes')))
        ->withoutOverlapping()
        ->onOneServer();
}

// Backtesting: freeze today's economic verdicts, then grade whatever
// snapshots' horizons have arrived. Evaluation runs a few minutes after
// snapshotting so the same run never grades a decision it just took.
Schedule::command('fantasy:snapshot-decisions')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.decision_snapshots_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:evaluate-decisions')
    ->cron(CronFrequency::everyMinutes((int) config('fantasy.sync.decision_evaluation_frequency_minutes')))
    ->withoutOverlapping()
    ->onOneServer();
