<?php

namespace App\Services\Notifications;

use App\Jobs\DeliverAlertJob;
use App\Models\FantasyAccount;
use App\Models\FantasyAlert;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes in-app notifications (fantasy_alerts, channel IN_APP) for things the
 * user would otherwise only find by opening the right screen: an offer on one
 * of their listings, an auction they bid on ending, an automatic clause order
 * changing state, the LaLiga session expiring. The frontend polls these
 * (bell), and each one is also pushed to the user's phone/browser and
 * Telegram when connected (DeliverAlertJob).
 *
 * Never throws: a notification failing must never break the sync or action
 * that triggered it.
 */
class AlertService
{
    /**
     * @param  array{player_id?: ?int, link?: ?string, severity?: string, dedupe?: ?string}  $options
     */
    public function notify(FantasyAccount|int $account, string $type, string $title, string $message, array $options = []): ?FantasyAlert
    {
        $accountId = $account instanceof FantasyAccount ? $account->id : $account;
        $dedupe = $options['dedupe'] ?? null;

        try {
            if ($dedupe && FantasyAlert::where('fantasy_account_id', $accountId)->where('dedupe_key', $dedupe)->exists()) {
                return null;
            }

            $alert = FantasyAlert::create([
                'fantasy_account_id' => $accountId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $options['link'] ?? null,
                'dedupe_key' => $dedupe,
                'fantasy_player_id' => $options['player_id'] ?? null,
                'severity' => $options['severity'] ?? FantasyAlert::SEVERITY_INFO,
                'channel' => 'IN_APP',
            ]);

            // Out to the phone/Telegram in the background — never delays or breaks the caller.
            try {
                DeliverAlertJob::dispatch($alert->id);
            } catch (Throwable $e) {
                Log::warning('fantasy.alerts.dispatch_failed', ['message' => $e->getMessage()]);
            }

            return $alert;
        } catch (QueryException $e) {
            // Unique (account, dedupe_key) race between two syncs — already notified.
            return null;
        } catch (Throwable $e) {
            Log::warning('fantasy.alerts.notify_failed', ['type' => $type, 'message' => $e->getMessage()]);

            return null;
        }
    }
}
