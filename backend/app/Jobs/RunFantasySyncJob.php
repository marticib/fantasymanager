<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunFantasySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly int $fantasyAccountId) {}

    public function handle(): void
    {
        try {
            Artisan::call('fantasy:sync', ['--account' => $this->fantasyAccountId]);
        } finally {
            Cache::forget(self::runningKey($this->fantasyAccountId));
        }
    }

    public function failed(?Throwable $e): void
    {
        Cache::forget(self::runningKey($this->fantasyAccountId));
    }

    /** TTL is only a safety net in case the worker dies mid-job. */
    public static function markRunning(int $accountId): void
    {
        Cache::put(self::runningKey($accountId), now()->toIso8601String(), now()->addMinutes(10));
    }

    public static function isRunning(int $accountId): bool
    {
        return Cache::has(self::runningKey($accountId));
    }

    private static function runningKey(int $accountId): string
    {
        return "fantasy:sync-running:{$accountId}";
    }
}
