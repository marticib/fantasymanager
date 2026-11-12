<?php

namespace App\Jobs;

use App\Models\FantasyAlert;
use App\Services\Notifications\PushNotifier;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one in-app notification out to the account's phone/browser (Web Push) and Telegram. */
class DeliverAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(private readonly int $alertId) {}

    public function handle(PushNotifier $push, TelegramNotifier $telegram): void
    {
        $alert = FantasyAlert::with('account')->find($this->alertId);

        if (! $alert || ! $alert->account) {
            return;
        }

        $payload = ['title' => $alert->title, 'body' => $alert->message, 'link' => $alert->link, 'tag' => 'alert-'.$alert->id];

        $push->send($alert->fantasy_account_id, $payload);

        if ($alert->account->telegram_chat_id) {
            $telegram->send($alert->account->telegram_chat_id, $telegram->format($payload));
        }
    }
}
