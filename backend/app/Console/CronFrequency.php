<?php

namespace App\Console;

class CronFrequency
{
    /**
     * Converts a "run every N minutes" config value into a cron expression
     * that actually means that. A cron minute field only ever matches 0-59
     * — a plain minute-step expression for N >= 60 silently collapses to
     * "minute 0 of every hour" (the only multiple of N that falls in
     * range), a real bug caught in production: sync-clauses (every 120
     * min, meant every 2h), sync-external-trends (every 180 min, meant
     * every 3h) and snapshot/evaluate-decisions (every 720 min, meant
     * every 12h) were all firing hourly instead — confirmed live via
     * `php artisan schedule:list` on the production server.
     *
     * N < 60 keeps the simple minute-step form (correct as-is); N >= 60
     * that's a clean multiple of 60 converts to an hour-step expression
     * instead.
     */
    public static function everyMinutes(int $minutes): string
    {
        if ($minutes < 60 || $minutes % 60 !== 0) {
            return "*/{$minutes} * * * *";
        }

        return '0 */'.intdiv($minutes, 60).' * * *';
    }
}
