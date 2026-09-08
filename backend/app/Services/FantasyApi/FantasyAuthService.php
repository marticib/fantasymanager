<?php

namespace App\Services\FantasyApi;

use App\Models\FantasyAccount;
use App\Services\FantasyApi\Exceptions\FantasyApiAuthenticationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the LaLiga Fantasy token lifecycle for a FantasyAccount.
 *
 * We never see the user's LaLiga password. Two ways in:
 *
 *  - startInteractiveLogin()/finishInteractiveLogin(): the real Authorization
 *    Code + PKCE flow LaLiga's own apps use (see config/fantasy.php `auth`
 *    for the provenance note on why the user has to paste back one URL by
 *    hand instead of a normal redirect).
 *  - storeManualTokens(): pasting an access_token/refresh_token pair
 *    captured from an already-authenticated session (DevTools, the
 *    bookmarklet, ...), kept as a fallback.
 *
 * From then on this service keeps the session alive by calling the real
 * Azure AD B2C refresh_token grant LaLiga's own clients use.
 *
 * Tokens are stored encrypted (see FantasyAccount's casts) and are never
 * logged or returned to the frontend.
 */
class FantasyAuthService
{
    public function getValidAccessToken(FantasyAccount $account): string
    {
        if (! $account->hasValidTokens()) {
            throw new FantasyApiAuthenticationException(
                'No LaLiga Fantasy tokens configured for this account. Paste an access_token and refresh_token in Settings.'
            );
        }

        $margin = (int) config('fantasy.auth.refresh_margin_seconds');

        $needsRefresh = $account->token_expires_at !== null
            && now()->addSeconds($margin)->greaterThanOrEqualTo($account->token_expires_at);

        if ($needsRefresh) {
            $this->refresh($account);
        }

        return $account->access_token;
    }

    public function refresh(FantasyAccount $account): void
    {
        if (blank($account->refresh_token)) {
            throw new FantasyApiAuthenticationException(
                'The stored session has no refresh_token, and it can no longer be renewed automatically. Paste fresh tokens in Settings.'
            );
        }

        $endpoint = config('fantasy.auth.token_endpoint').'?p='.config('fantasy.auth.refresh_policy');
        // A refresh must use the same client that issued the tokens. Tokens
        // from our own interactive login carry that client_id; tokens pasted
        // in from an unknown session (DevTools/bookmarklet) fall back to the
        // default web client, matching what the official web app itself uses.
        $clientId = $account->token_client_id ?: config('fantasy.auth.refresh_client_id');

        try {
            $response = Http::asForm()
                ->timeout((int) config('fantasy.api.timeout'))
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; FantasyAssistant/1.0)'])
                ->post($endpoint, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->refresh_token,
                    'client_id' => $clientId,
                    'scope' => config('fantasy.auth.refresh_scope'),
                ]);
        } catch (Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.auth.refresh.network_error', ['message' => $e->getMessage()]);
            throw new FantasyApiAuthenticationException('Could not reach the LaLiga Fantasy auth server to refresh the session.');
        }

        if ($response->status() === 400 || $response->status() === 401) {
            Log::channel('fantasy_api')->warning('fantasy.auth.refresh.invalid_grant', ['account_id' => $account->id]);
            throw new FantasyApiAuthenticationException(
                'The LaLiga Fantasy session has expired (invalid_grant). Paste fresh tokens in Settings.'
            );
        }

        if ($response->failed()) {
            Log::channel('fantasy_api')->error('fantasy.auth.refresh.failed', [
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);
            throw new FantasyApiAuthenticationException('LaLiga Fantasy token refresh failed with status '.$response->status().'.');
        }

        $body = $response->json() ?? [];
        $accessToken = $body['access_token'] ?? $body['id_token'] ?? null;

        if (! $accessToken) {
            throw new FantasyApiAuthenticationException('Token refresh response did not contain an access_token or id_token.');
        }

        $expiresIn = $body['expires_in'] ?? $body['id_token_expires_in'] ?? 86400;

        $account->forceFill([
            'access_token' => $accessToken,
            // B2C rotates the refresh token on every use; keep the old one if a new one wasn't issued.
            'refresh_token' => $body['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => $this->resolveExpiry($accessToken) ?? now()->addSeconds((int) $expiresIn),
        ])->save();

        Log::channel('fantasy_api')->info('fantasy.auth.refresh.success', ['account_id' => $account->id]);
    }

    /**
     * Stores tokens pasted in manually from Settings. If expiresInSeconds is
     * not given we try to read the JWT's `exp` claim (unverified — we only
     * need the timestamp, not to trust the token's authenticity, since it is
     * sent straight to LaLiga's own API which will reject it if invalid).
     */
    public function storeManualTokens(
        FantasyAccount $account,
        string $accessToken,
        ?string $refreshToken,
        ?int $expiresInSeconds = null,
    ): void {
        $expiresAt = $expiresInSeconds
            ? now()->addSeconds($expiresInSeconds)
            : ($this->resolveExpiry($accessToken) ?? now()->addHours(24));

        $account->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_client_id' => null,
            'token_expires_at' => $expiresAt,
        ])->save();
    }

    /**
     * Step 1 of the interactive login: builds the real LaLiga B2C authorize
     * URL with a fresh PKCE challenge, and stashes the verifier server-side
     * (never sent to the frontend) so finishInteractiveLogin() can use it.
     */
    public function startInteractiveLogin(FantasyAccount $account): string
    {
        $verifier = $this->base64UrlEncode(random_bytes(64));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $state = Str::random(32);

        Cache::put($this->pkceCacheKey($account), [
            'code_verifier' => $verifier,
            'state' => $state,
        ], now()->addSeconds((int) config('fantasy.auth.oauth_session_ttl_seconds')));

        $params = [
            'p' => config('fantasy.auth.refresh_policy'),
            'client_id' => config('fantasy.auth.oauth_client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('fantasy.auth.oauth_redirect_uri'),
            'scope' => config('fantasy.auth.oauth_scope'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'nonce' => $state,
        ];

        return config('fantasy.auth.authorize_endpoint').'?'.http_build_query($params);
    }

    /**
     * Step 2: the user pasted back the URL (or bare `code`) their browser
     * failed to navigate to after logging in. Exchanges it for tokens.
     */
    public function finishInteractiveLogin(FantasyAccount $account, string $pastedRedirect): void
    {
        $pkce = Cache::pull($this->pkceCacheKey($account));

        if (! $pkce) {
            throw new FantasyApiAuthenticationException(
                "L'enllaç de login ha caducat. Torna a generar-lo i fes-ho de seguida."
            );
        }

        ['code' => $code, 'state' => $state] = $this->extractCodeAndState($pastedRedirect);

        if (! $code) {
            throw new FantasyApiAuthenticationException(
                "No s'ha trobat cap 'code' a la URL enganxada. Enganxa la URL completa (o el codi) tal com apareix a les DevTools."
            );
        }

        if ($state && $state !== $pkce['state']) {
            throw new FantasyApiAuthenticationException(
                "El 'state' no coincideix amb l'intent de login actual. Torna a generar l'enllaç i no reutilitzis un codi antic."
            );
        }

        $clientId = config('fantasy.auth.oauth_client_id');
        $endpoint = config('fantasy.auth.token_endpoint').'?p='.config('fantasy.auth.refresh_policy');

        try {
            $response = Http::asForm()
                ->timeout((int) config('fantasy.api.timeout'))
                ->post($endpoint, [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientId,
                    'code' => $code,
                    'redirect_uri' => config('fantasy.auth.oauth_redirect_uri'),
                    'code_verifier' => $pkce['code_verifier'],
                    'scope' => config('fantasy.auth.oauth_scope'),
                ]);
        } catch (Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.auth.oauth.network_error', ['message' => $e->getMessage()]);
            throw new FantasyApiAuthenticationException('No s\'ha pogut contactar amb el servidor de login de LaLiga.');
        }

        if ($response->status() === 400 || $response->status() === 401) {
            Log::channel('fantasy_api')->warning('fantasy.auth.oauth.invalid_grant', ['account_id' => $account->id]);
            throw new FantasyApiAuthenticationException(
                "El codi de login ha estat rebutjat. És d'un sol ús i caduca en un parell de minuts — torna a generar l'enllaç i enganxa la URL de seguida, sense reutilitzar-ne una d'antiga."
            );
        }

        if ($response->failed()) {
            Log::channel('fantasy_api')->error('fantasy.auth.oauth.failed', [
                'account_id' => $account->id,
                'status' => $response->status(),
            ]);
            throw new FantasyApiAuthenticationException('El bescanvi del codi de login ha fallat amb estat '.$response->status().'.');
        }

        $body = $response->json() ?? [];
        $accessToken = $body['access_token'] ?? $body['id_token'] ?? null;

        if (! $accessToken) {
            throw new FantasyApiAuthenticationException('La resposta de login no contenia cap access_token ni id_token.');
        }

        $expiresIn = $body['expires_in'] ?? $body['id_token_expires_in'] ?? 86400;

        $account->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => $body['refresh_token'] ?? null,
            'token_client_id' => $clientId,
            'token_expires_at' => $this->resolveExpiry($accessToken) ?? now()->addSeconds((int) $expiresIn),
        ])->save();

        Log::channel('fantasy_api')->info('fantasy.auth.oauth.success', ['account_id' => $account->id]);
    }

    /**
     * Accepts either the full failed-redirect URL (any scheme — parse_url
     * handles `authredirect://...` fine) or a bare authorization code.
     *
     * @return array{code: ?string, state: ?string}
     */
    private function extractCodeAndState(string $pasted): array
    {
        $pasted = trim($pasted, " \t\n\r\0\x0B\"'");

        if (str_contains($pasted, '?')) {
            $query = explode('?', $pasted, 2)[1];
            parse_str($query, $params);

            return ['code' => $params['code'] ?? null, 'state' => $params['state'] ?? null];
        }

        return ['code' => $pasted !== '' ? $pasted : null, 'state' => null];
    }

    private function pkceCacheKey(FantasyAccount $account): string
    {
        return "fantasy_oauth_pkce:{$account->id}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function resolveExpiry(string $jwt): ?Carbon
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        if (! is_array($payload) || ! isset($payload['exp'])) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $payload['exp']);
    }
}
