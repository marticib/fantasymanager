<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyStanding;
use App\Services\Recommendation\RosterValueHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StandingController extends Controller
{
    /**
     * Both league tables off the latest standings capture: by points (LaLiga's
     * own order) and economic — each team's squad value (LaLiga's official
     * `teamValue`, public for every team) plus its cash. LaLiga only reveals
     * cash for your own team (a rival's comes back null / 403), so a rival's
     * is never guessed: the economic table ranks by squad value, and your
     * total includes your cash. Also how much each squad's value moved in 24h.
     */
    public function index(Request $request, RosterValueHistoryService $rosterHistory)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;

        if (! $league) {
            return response()->json(['data' => [], 'message' => 'Encara no has triat cap lliga. Tria-la a la configuració inicial.']);
        }

        $latestCapturedAt = FantasyStanding::where('fantasy_league_id', $league->id)->max('captured_at');

        $rows = FantasyStanding::query()
            ->where('fantasy_league_id', $league->id)
            ->where('captured_at', $latestCapturedAt)
            ->with('team')
            ->orderBy('position')
            ->get()
            ->map(function (FantasyStanding $s) use ($rosterHistory) {
                $team = $s->team;
                $isMine = (bool) $team?->is_mine;
                $teamValue = data_get($s->raw_payload, 'team.teamValue') ?? $team?->team_value;
                $cash = $isMine && $team->money !== null ? (int) $team->money : null;
                $delta = $team ? $rosterHistory->delta24h($team->players()->get()) : null;

                return [
                    'position' => $s->position,
                    'previousPosition' => data_get($s->raw_payload, 'previousPosition'),
                    'points' => $s->points,
                    'team' => $team?->name,
                    'manager' => data_get($s->raw_payload, 'team.manager.managerName'),
                    'teamId' => $team?->id,
                    'isMine' => $isMine,
                    'teamValue' => $teamValue !== null ? (int) $teamValue : null,
                    'cash' => $cash,
                    'totalValue' => $teamValue !== null && $cash !== null ? (int) $teamValue + $cash : null,
                    'valueChange24h' => $delta['delta'] ?? null,
                    'valueChange24hPct' => $delta['pct'] ?? null,
                ];
            });

        return response()->json(['data' => $rows, 'capturedAt' => $latestCapturedAt ? Carbon::parse($latestCapturedAt)->toIso8601String() : null]);
    }
}
