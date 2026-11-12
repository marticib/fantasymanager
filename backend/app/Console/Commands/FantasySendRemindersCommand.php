<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesFantasyAccounts;
use App\Services\Notifications\ReminderService;
use Illuminate\Console\Command;

/** Time-based notifications (cheap clauses, market closing, weekly recap) — see ReminderService. */
class FantasySendRemindersCommand extends Command
{
    use ResolvesFantasyAccounts;

    protected $signature = 'fantasy:send-reminders {--account= : Only this fantasy_account id}';

    protected $description = 'Raise the time-based notifications that are due (cheap clauses, market closing, weekly recap)';

    public function handle(ReminderService $reminders): int
    {
        foreach ($this->resolveAccounts()->whereNotNull('active_team_id') as $account) {
            $raised = $reminders->run($account);
            $this->info("Account #{$account->id}: ".($raised ? implode(', ', $raised) : 'nothing due'));
        }

        return self::SUCCESS;
    }
}
