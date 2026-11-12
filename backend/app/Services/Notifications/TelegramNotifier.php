<?php

namespace App\Services\Notifications;

use App\Models\FantasyAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Forwards notifications to a Telegram chat through the app's own bot.
 * Linking needs no webhook: the user opens t.me/<bot>?start=<code>, presses
 * Start, and verify() finds that /start message with getUpdates.
 */
class TelegramNotifier
{
    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.bot_username'));
    }

    public function botUsername(): ?string
    {
        return config('services.telegram.bot_username');
    }

    /** A fresh one-time code and the link that sends it to the bot. */
    public function startLink(FantasyAccount $account): string
    {
        $code = Str::lower(Str::random(10));
        $account->forceFill(['telegram_link_code' => $code])->save();

        return 'https://t.me/'.$this->botUsername().'?start='.$code;
    }

    /** Looks for the user's "/start <code>" in the bot's recent messages and links that chat. */
    public function verify(FantasyAccount $account): bool
    {
        $code = $account->telegram_link_code;

        if (! $code || ! $this->isConfigured()) {
            return false;
        }

        try {
            $updates = Http::timeout(10)->get($this->api('getUpdates'), ['allowed_updates' => json_encode(['message'])])->json('result') ?? [];
        } catch (Throwable $e) {
            Log::warning('fantasy.telegram.updates_failed', ['message' => $e->getMessage()]);

            return false;
        }

        foreach (array_reverse($updates) as $update) {
            $text = trim((string) data_get($update, 'message.text'));
            $chatId = data_get($update, 'message.chat.id');

            if ($chatId !== null && $text === '/start '.$code) {
                $account->forceFill(['telegram_chat_id' => (string) $chatId, 'telegram_link_code' => null])->save();
                $this->send((string) $chatId, '✅ <b>Connectat</b>'."\n".'Aquí rebràs els avisos de Fantasy Assistant.');

                return true;
            }
        }

        return false;
    }

    public function send(string $chatId, string $html): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            return Http::timeout(10)->post($this->api('sendMessage'), [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ])->successful();
        } catch (Throwable $e) {
            Log::warning('fantasy.telegram.send_failed', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /** @param  array{title: string, body: string, link?: ?string}  $payload */
    public function format(array $payload): string
    {
        $html = '<b>'.e($payload['title']).'</b>'."\n".e($payload['body']);

        if (! empty($payload['link'])) {
            $html .= "\n".rtrim((string) config('services.frontend_url'), '/').$payload['link'];
        }

        return $html;
    }

    private function api(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
