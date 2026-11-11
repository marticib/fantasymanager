<?php

namespace App\Http\Middleware;

use App\Support\DataVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Any successful write (bid, cancel, accept/reject, settings, clause order,
 * league select, manual sync trigger...) invalidates the account's cached API
 * responses (see CacheApiResponse), so the next read reflects it immediately.
 */
class BumpDataVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && $response->isSuccessful() && $request->user()) {
            $accountId = $request->user()->fantasyAccounts()->value('id');

            if ($accountId) {
                DataVersion::bumpAccount($accountId);
            }
        }

        return $response;
    }
}
