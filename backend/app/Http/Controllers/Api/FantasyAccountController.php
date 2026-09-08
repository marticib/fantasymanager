<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyAccount;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\FantasyAuthService;
use App\Services\FantasyApi\FantasyLeagueService;
use Illuminate\Http\Request;

class FantasyAccountController extends Controller
{
    public function show(Request $request)
    {
        $account = $this->currentAccount($request);

        return response()->json([
            'id' => $account->id,
            'nickname' => $account->nickname,
            'hasTokens' => $account->hasValidTokens(),
            'tokenExpiresAt' => $account->token_expires_at?->toIso8601String(),
            'activeLeagueId' => $account->active_league_id,
            'activeTeamId' => $account->active_team_id,
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
        ]);
    }

    public function storeTokens(Request $request, FantasyAuthService $auth, FantasyLeagueService $leagueService)
    {
        $data = $request->validate([
            'access_token' => ['required', 'string'],
            'refresh_token' => ['nullable', 'string'],
            'expires_in' => ['nullable', 'integer', 'min:60'],
        ]);

        $account = $this->currentAccount($request);

        $auth->storeManualTokens($account, $data['access_token'], $data['refresh_token'] ?? null, $data['expires_in'] ?? null);

        return $this->confirmSession($account, $leagueService);
    }

    /**
     * Step 1 of the interactive LaLiga login (see FantasyAuthService docblock
     * for why this can't be a plain OAuth redirect): returns the real B2C
     * authorize URL to open in a new tab.
     */
    public function startOAuth(Request $request, FantasyAuthService $auth)
    {
        $account = $this->currentAccount($request);

        return response()->json(['url' => $auth->startInteractiveLogin($account)]);
    }

    /**
     * Step 2: the user pasted back the failed-redirect URL (or bare code)
     * from their browser's DevTools after logging in.
     */
    public function finishOAuth(Request $request, FantasyAuthService $auth, FantasyLeagueService $leagueService)
    {
        $data = $request->validate([
            'redirect' => ['required', 'string'],
        ]);

        $account = $this->currentAccount($request);

        try {
            $auth->finishInteractiveLogin($account, $data['redirect']);
        } catch (FantasyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->confirmSession($account, $leagueService);
    }

    public function destroy(Request $request)
    {
        $account = $this->currentAccount($request);

        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_client_id' => null,
            'token_expires_at' => null,
        ])->save();

        return response()->json(['message' => 'LaLiga Fantasy session cleared.']);
    }

    /**
     * Shared "we just got fresh tokens, confirm they work" step: fetches
     * /v4/user/me so the UI can show a nickname, common to both auth paths.
     */
    private function confirmSession(FantasyAccount $account, FantasyLeagueService $leagueService)
    {
        try {
            $me = $leagueService->getCurrentUser($account);
            $account->forceFill(['fantasy_user_id' => $me->id, 'nickname' => $me->username])->save();
        } catch (FantasyApiException $e) {
            return response()->json([
                'message' => 'Tokens saved, but calling LaLiga Fantasy failed — double check they are valid.',
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['message' => 'Tokens saved.', 'nickname' => $account->nickname]);
    }
}
