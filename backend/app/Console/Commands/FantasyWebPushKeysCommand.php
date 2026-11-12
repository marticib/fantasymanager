<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Prints a fresh VAPID key pair for Web Push, to paste into .env once. */
class FantasyWebPushKeysCommand extends Command
{
    protected $signature = 'fantasy:webpush-keys';

    protected $description = 'Generate the VAPID key pair Web Push notifications need (add the output to .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Add these two lines to .env (keep them: changing them unsubscribes every device), then run php artisan config:cache.');

        return self::SUCCESS;
    }
}
