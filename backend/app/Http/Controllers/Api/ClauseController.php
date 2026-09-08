<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\ClauseOpportunityService;
use Illuminate\Http\Request;

class ClauseController extends Controller
{
    public function opportunities(Request $request, ClauseOpportunityService $service)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $hasRivalData = $team->league?->teams()
            ->where('is_mine', false)
            ->whereHas('teamPlayers')
            ->exists() ?? false;

        return response()->json([
            'data' => $service->evaluate($account)->values(),
            'available' => $hasRivalData,
            'message' => $hasRivalData
                ? null
                : "Encara no s'han sincronitzat les plantilles rivals d'aquesta lliga. Prova de tornar a sincronitzar en uns minuts.",
        ]);
    }
}
