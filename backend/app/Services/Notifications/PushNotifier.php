<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Web Push to every browser/device the account subscribed (Settings →
 * Notificacions). Works with the app closed — on a phone, once installed
 * to the home screen. Expired subscriptions are dropped as they're found.
 */
class PushNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public function publicKey(): ?string
    {
        return config('services.webpush.public_key');
    }

    /**
     * @param  array{title: string, body: string, link?: ?string, tag?: string}  $payload
     * @return int deliveries accepted by the push services
     */
    public function send(int $accountId, array $payload): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $subscriptions = PushSubscription::where('fantasy_account_id', $accountId)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 6 * 3600, 'urgency' => 'high']);

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

            foreach ($subscriptions as $s) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $s->endpoint,
                    'publicKey' => $s->public_key,
                    'authToken' => $s->auth_token,
                    'contentEncoding' => $s->content_encoding,
                ]), $body);
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', PushSubscription::hashFor($report->getEndpoint()))->delete();
                } else {
                    Log::warning('fantasy.push.failed', ['reason' => $report->getReason()]);
                }
            }

            return $sent;
        } catch (Throwable $e) {
            Log::warning('fantasy.push.error', ['message' => $e->getMessage()]);

            return 0;
        }
    }
}
