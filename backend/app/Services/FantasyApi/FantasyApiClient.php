<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\Exceptions\FantasyApiAuthenticationException;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\Exceptions\FantasyApiRateLimitException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single door through which the app talks to fantasy-api.llt-services.com.
 *
 * Nothing outside app/Services/FantasyApi should know an HTTP path, header or
 * status code from LaLiga's API. If a route changes, this class (or the
 * relevant *Service) is the only thing that needs to change.
 *
 * Responsibilities:
 *  - injects a valid bearer token per account (via FantasyAuthService)
 *  - retries transiently-failing requests (connection errors, 5xx)
 *  - on 401: refreshes the token once and retries the request exactly once
 *  - on 429: honors Retry-After (bounded) before giving up with a typed exception
 *  - logs every call (method, path, status, duration) without ever logging a token
 */
class FantasyApiClient
{
    private const MAX_429_RETRIES = 2;

    public function __construct(private readonly FantasyAuthService $auth) {}

    public function get(FantasyAccount $account, string $path, array $query = []): array
    {
        return $this->request($account, 'get', $path, ['query' => $query]);
    }

    public function post(FantasyAccount $account, string $path, array $data = []): array
    {
        return $this->request($account, 'post', $path, ['json' => $data]);
    }

    public function put(FantasyAccount $account, string $path, array $data = []): array
    {
        return $this->request($account, 'put', $path, ['json' => $data]);
    }

    public function delete(FantasyAccount $account, string $path): array
    {
        return $this->request($account, 'delete', $path);
    }

    private function request(
        FantasyAccount $account,
        string $method,
        string $path,
        array $options = [],
        bool $isAuthRetry = false,
        int $rateLimitAttempt = 0,
    ): array {
        $token = $this->auth->getValidAccessToken($account);
        $url = $this->buildUrl($path);
        $query = array_merge(config('fantasy.api.default_query', []), $options['query'] ?? []);

        $started = microtime(true);

        try {
            $response = $this->newRequest($token)->{$method}($url, $method === 'get' ? $query : ($options['json'] ?? []));
        } catch (ConnectionException $e) {
            Log::channel('fantasy_api')->error('fantasy.api.connection_error', [
                'method' => $method, 'path' => $path, 'message' => $e->getMessage(),
            ]);
            throw new FantasyApiException("Connection to LaLiga Fantasy API failed: {$e->getMessage()}");
        }

        $durationMs = (int) ((microtime(true) - $started) * 1000);

        Log::channel('fantasy_api')->debug('fantasy.api.request', [
            'method' => $method,
            'path' => $path,
            'status' => $response->status(),
            'duration_ms' => $durationMs,
        ]);

        if ($response->status() === 401 && ! $isAuthRetry) {
            $this->auth->refresh($account);

            return $this->request($account, $method, $path, $options, isAuthRetry: true, rateLimitAttempt: $rateLimitAttempt);
        }

        if ($response->status() === 401) {
            throw new FantasyApiAuthenticationException('LaLiga Fantasy rejected the request as unauthorized even after refreshing the token.');
        }

        if ($response->status() === 429) {
            if ($rateLimitAttempt >= self::MAX_429_RETRIES) {
                throw new FantasyApiRateLimitException(
                    'LaLiga Fantasy API rate limit exceeded.',
                    $this->retryAfterSeconds($response),
                );
            }

            $sleepSeconds = $this->retryAfterSeconds($response) ?? (1 + $rateLimitAttempt);
            Log::channel('fantasy_api')->warning('fantasy.api.rate_limited', ['path' => $path, 'sleep_seconds' => $sleepSeconds]);
            sleep(min($sleepSeconds, 10));

            return $this->request($account, $method, $path, $options, $isAuthRetry, $rateLimitAttempt + 1);
        }

        if ($response->serverError()) {
            throw new FantasyApiException(
                "LaLiga Fantasy API returned a server error ({$response->status()}) for {$path}.",
                $response->status(),
                $response->json(),
            );
        }

        if ($response->clientError()) {
            throw new FantasyApiException(
                "LaLiga Fantasy API rejected the request ({$response->status()}) for {$path}.",
                $response->status(),
                $response->json(),
            );
        }

        if ($response->status() === 204 || $response->body() === '') {
            return [];
        }

        return $response->json() ?? [];
    }

    private function newRequest(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout((int) config('fantasy.api.timeout'))
            ->retry(
                (int) config('fantasy.api.retry_times'),
                (int) config('fantasy.api.retry_sleep_ms'),
                fn ($exception, $request) => $exception instanceof ConnectionException,
                throw: false,
            )
            ->acceptJson();
    }

    private function buildUrl(string $path): string
    {
        return rtrim((string) config('fantasy.api.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }
}
