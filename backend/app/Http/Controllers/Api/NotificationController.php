<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\Notifications\PushNotifier;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Http\Request;

/** Settings → Notificacions: phone/browser push and Telegram. */
class NotificationController extends Controller
{
    public function channels(Request $request, PushNotifier $push, TelegramNotifier $telegram)
    {
        $account = $this->currentAccount($request);

        return response()->json([
            'push' => [
                'configured' => $push->isConfigured(),
                'publicKey' => $push->publicKey(),
                'devices' => PushSubscription::where('fantasy_account_id', $account->id)->count(),
            ],
            'telegram' => [
                'configured' => $telegram->isConfigured(),
                'botUsername' => $telegram->botUsername(),
                'connected' => $account->telegram_chat_id !== null,
            ],
        ]);
    }

    public function subscribe(Request $request, PushNotifier $push)
    {
        abort_unless($push->isConfigured(), 422, 'Les notificacions push no estan configurades al servidor.');

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'fantasy_account_id' => $this->currentAccount($request)->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $endpoint = (string) $request->input('endpoint');

        PushSubscription::where('fantasy_account_id', $this->currentAccount($request)->id)
            ->where('endpoint_hash', PushSubscription::hashFor($endpoint))
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function telegramLink(Request $request, TelegramNotifier $telegram)
    {
        abort_unless($telegram->isConfigured(), 422, 'El bot de Telegram no està configurat al servidor.');

        return response()->json(['url' => $telegram->startLink($this->currentAccount($request))]);
    }

    public function telegramVerify(Request $request, TelegramNotifier $telegram)
    {
        $connected = $telegram->verify($this->currentAccount($request));

        return response()->json([
            'connected' => $connected,
            'message' => $connected ? 'Telegram connectat.' : 'Encara no hem rebut el teu missatge. Obre l\'enllaç, prem «Iniciar» al bot i torna-ho a provar.',
        ], $connected ? 200 : 422);
    }

    public function telegramDisconnect(Request $request)
    {
        $this->currentAccount($request)->forceFill(['telegram_chat_id' => null, 'telegram_link_code' => null])->save();

        return response()->json(['ok' => true]);
    }

    /** Sends a test message through every connected channel. */
    public function test(Request $request, PushNotifier $push, TelegramNotifier $telegram)
    {
        $account = $this->currentAccount($request);
        $payload = ['title' => 'Prova de notificació', 'body' => 'Si veus això, els avisos et funcionen. 👍', 'link' => '/', 'tag' => 'test'];

        $pushed = $push->send($account->id, $payload);
        $telegramSent = $account->telegram_chat_id ? $telegram->send($account->telegram_chat_id, $telegram->format($payload)) : false;

        return response()->json(['push' => $pushed, 'telegram' => $telegramSent]);
    }
}
