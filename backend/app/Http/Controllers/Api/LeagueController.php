<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyLeagueResource;
use App\Http\Resources\FantasyTeamResource;
use App\Models\FantasyLeague;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\Sync\FantasySyncService;
use Illuminate\Http\Request;

class LeagueController extends Controller
{
    public function index(Request $request, FantasySyncService $sync)
    {
        $account = $this->currentAccount($request);

        try {
            $leagues = $sync->syncLeagues($account);
        } catch (FantasyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return FantasyLeagueResource::collection($leagues)->additional([
            'activeLeagueId' => $account->active_league_id,
        ]);
    }

    public function select(Request $request, FantasyLeague $league, FantasySyncService $sync)
    {
        $account = $this->currentAccount($request);

        if ($league->fantasy_account_id !== $account->id) {
            abort(403);
        }

        $data = $request->validate([
            'team_external_id' => ['nullable', 'string'],
        ]);

        try {
            $team = $sync->selectLeague($account, $league, $data['team_external_id'] ?? null);
        } catch (\RuntimeException|FantasyApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'league' => new FantasyLeagueResource($league),
            'team' => new FantasyTeamResource($team),
        ]);
    }
}
