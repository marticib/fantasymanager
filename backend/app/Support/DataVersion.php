<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A per-account data "version" used as part of every cached API response key
 * (see CacheApiResponse). Everything those responses are computed from only
 * changes when a sync writes new data or the user performs an action, so
 * bumping the version at those points is what invalidates the cache — no
 * need to know which keys exist. Two counters: one per account (its own
 * league/team/market data) and one global (shared data: the player catalog,
 * external trends, daily reports).
 */
class DataVersion
{
    public static function forAccount(int $accountId): string
    {
        return self::read('global').':'.self::read("account:{$accountId}");
    }

    public static function bumpAccount(int $accountId): void
    {
        self::bump("account:{$accountId}");
    }

    public static function bumpGlobal(): void
    {
        self::bump('global');
    }

    private static function read(string $scope): int
    {
        return (int) Cache::get(self::key($scope), 0);
    }

    private static function bump(string $scope): void
    {
        $key = self::key($scope);

        // increment() is a no-op returning false on a missing key for some
        // stores — seed it first so the very first bump always changes the version.
        Cache::add($key, 0);
        Cache::increment($key);
    }

    private static function key(string $scope): string
    {
        return "fantasy:data-version:{$scope}";
    }
}
