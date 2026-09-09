<?php

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
    ->cron('*/'.config('fantasy.sync.players_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:sync-market')
    ->cron('*/'.config('fantasy.sync.market_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:sync-team')
    ->cron('*/'.config('fantasy.sync.team_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:generate-recommendations')
    ->cron('*/'.config('fantasy.sync.recommendations_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

// Rival rosters change far less often than the market, and this walks every
// other team in the league (one API call each), so it gets its own slower
// cadence rather than piggybacking on fantasy:sync-team.
Schedule::command('fantasy:sync-clauses')
    ->cron('*/'.config('fantasy.sync.clauses_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

if (config('fantasy.external.enabled')) {
    Schedule::command('fantasy:sync-external-trends')
        ->cron('*/'.config('fantasy.external.sync_frequency_minutes').' * * * *')
        ->withoutOverlapping()
        ->onOneServer();
}

// Backtesting: freeze today's economic verdicts, then grade whatever
// snapshots' horizons have arrived. Evaluation runs a few minutes after
// snapshotting so the same run never grades a decision it just took.
Schedule::command('fantasy:snapshot-decisions')
    ->cron('*/'.config('fantasy.sync.decision_snapshots_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fantasy:evaluate-decisions')
    ->cron('*/'.config('fantasy.sync.decision_evaluation_frequency_minutes').' * * * *')
    ->withoutOverlapping()
    ->onOneServer();
