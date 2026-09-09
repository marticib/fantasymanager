<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyStanding;
use Illuminate\Http\Request;

class StandingController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;

        if (! $league) {
            return response()->json(['data' => [], 'message' => 'No active league selected yet.']);
        }

        $latestCapturedAt = FantasyStanding::where('fantasy_league_id', $league->id)->max('captured_at');

        $rows = FantasyStanding::query()
            ->where('fantasy_league_id', $league->id)
            ->where('captured_at', $latestCapturedAt)
            ->with('team')
            ->orderBy('position')
            ->get()
            ->map(fn (FantasyStanding $s) => [
                'position' => $s->position,
                'points' => $s->points,
                'team' => $s->team?->name,
                'teamId' => $s->team?->id,
                'isMine' => (bool) $s->team?->is_mine,
            ]);

        return response()->json(['data' => $rows]);
    }
}
