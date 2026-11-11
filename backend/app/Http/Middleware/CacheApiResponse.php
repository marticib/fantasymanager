<?php

namespace App\Http\Middleware;

use App\Support\DataVersion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caches a heavy read endpoint's JSON per account until the account's data
 * version changes (a sync finished, or the user did something — see
 * DataVersion and BumpDataVersion). These endpoints re-derive scores/trends
 * for every player from the DB on each call (hundreds of queries), yet the
 * underlying data only moves every sync cycle. The TTL is just a backstop for
 * the few time-relative fields (urgency, "hours left") computed server-side.
 */
class CacheApiResponse
{
    public const TTL_SECONDS = 900;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->user()) {
            return $next($request);
        }

        $accountId = $request->user()->fantasyAccounts()->value('id');

        if (! $accountId) {
            return $next($request);
        }

        // Version is read *before* computing, so a response built while a
        // sync/action lands is stored under the old version and never served
        // again — never under the new one.
        $key = 'fantasy:api-cache:'.$accountId.':'.DataVersion::forAccount($accountId).':'.sha1($request->getRequestUri());

        $cached = Cache::get($key);

        if ($cached !== null) {
            return response($cached, 200, ['Content-Type' => 'application/json', 'X-Api-Cache' => 'hit']);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 200) {
            Cache::put($key, $response->getContent(), self::TTL_SECONDS);
            $response->headers->set('X-Api-Cache', 'miss');
        }

        return $response;
    }
}
