<?php

namespace Tests\Unit\Console;

use App\Console\CronFrequency;
use Tests\TestCase;

class CronFrequencyTest extends TestCase
{
    // Regression test for a real production bug: a cron minute field only
    // ever matches 0-59, so the previous unconditional minute-step
    // expression for a frequency >= 60 (clauses: 120, external trends: 180,
    // snapshot/evaluate-decisions: 720) silently collapsed to "minute 0 of
    // every hour" — those jobs were firing hourly instead of every
    // 2h/3h/12h. Confirmed live via `php artisan schedule:list` on the
    // production server.
    public function test_a_clean_multiple_of_60_converts_to_an_hour_step(): void
    {
        $this->assertSame('0 */2 * * *', CronFrequency::everyMinutes(120));
        $this->assertSame('0 */3 * * *', CronFrequency::everyMinutes(180));
        $this->assertSame('0 */12 * * *', CronFrequency::everyMinutes(720));
        $this->assertSame('0 */1 * * *', CronFrequency::everyMinutes(60));
    }

    public function test_under_60_minutes_keeps_the_simple_minute_step_form(): void
    {
        $this->assertSame('*/15 * * * *', CronFrequency::everyMinutes(15));
        $this->assertSame('*/30 * * * *', CronFrequency::everyMinutes(30));
        $this->assertSame('*/1 * * * *', CronFrequency::everyMinutes(1));
    }

    /** A frequency that isn't a clean multiple of 60 falls back to the (imperfect but not silently wrong) minute-step form. */
    public function test_a_non_clean_multiple_above_60_falls_back_to_minute_step(): void
    {
        $this->assertSame('*/90 * * * *', CronFrequency::everyMinutes(90));
    }
}
