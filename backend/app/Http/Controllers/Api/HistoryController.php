<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /** How far back the team-value chart goes. */
    private const TEAM_VALUE_WINDOW_DAYS = 30;

    /**
     * Team value over time, reconstructed from fantasy_player_snapshots
     * (there is no separate team-value table — the roster's own history
     * already carries this, and staying derived avoids two sources of truth
     * drifting apart). Bounded to the last 30 days rather than the account's
     * entire history, matching the same window used elsewhere (see
     * PlayerController's value chart).
     *
     * A sync can run several times a day, so a naive `SUM(market_value)
     * GROUP BY day` massively overcounts — it adds up every snapshot taken
     * that day, not one per player. This keeps only each player's latest
     * snapshot per day (via ROW_NUMBER, portable across the Postgres used
     * in production and the SQLite used in tests) before summing.
     *
     * Each day also carries its per-player breakdown (`players`) — every
     * roster member's own value that day, so the UI can show what the
     * total is actually made up of instead of just the aggregate. Each
     * player and the day total also carry the change vs. the previous day
     * *in this list* (not necessarily the calendar day before, if a day has
     * no snapshots at all) — `null` for a player's first appearance (just
     * bought, or the very first day in the window), since there's nothing
     * to compare against yet.
     */
    public function teamValue(Request $request)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['data' => [], 'message' => 'No active team selected yet.']);
        }

        $ranked = FantasyPlayerSnapshot::query()
            ->where('owner_team_id', $team->id)
            ->where('captured_at', '>=', now()->subDays(self::TEAM_VALUE_WINDOW_DAYS))
            ->selectRaw(
                'DATE(captured_at) as day, fantasy_player_id, market_value, '.
                'ROW_NUMBER() OVER (PARTITION BY DATE(captured_at), fantasy_player_id ORDER BY captured_at DESC) as rn'
            );

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->join('fantasy_players', 'fantasy_players.id', '=', 'ranked.fantasy_player_id')
            ->where('rn', 1)
            ->selectRaw(
                'ranked.day, ranked.fantasy_player_id as player_id, ranked.market_value, '.
                'fantasy_players.name as player_name, fantasy_players.position, fantasy_players.image_url'
            )
            ->orderBy('ranked.day')
            ->orderByDesc('ranked.market_value')
            ->get();

        $previousByPlayer = [];
        $previousTeamValue = null;

        $data = $rows->groupBy('day')->map(function ($playersForDay, $day) use (&$previousByPlayer, &$previousTeamValue) {
            $teamValue = (int) $playersForDay->sum('market_value');
            $previousDayTeamValue = $previousTeamValue;
            $teamValueChange = $previousDayTeamValue !== null ? $teamValue - $previousDayTeamValue : null;

            $players = $playersForDay->map(function ($r) use ($previousByPlayer) {
                $marketValue = (int) $r->market_value;
                $previous = $previousByPlayer[$r->player_id] ?? null;
                $change = $previous !== null ? $marketValue - $previous : null;

                return [
                    'id' => $r->player_id,
                    'name' => $r->player_name,
                    'position' => $r->position,
                    'imageUrl' => $r->image_url,
                    'marketValue' => $marketValue,
                    'change' => $change,
                    'changePct' => ($change !== null && $previous > 0) ? round($change / $previous * 100, 1) : null,
                ];
            })->values();

            $previousByPlayer = $playersForDay->pluck('market_value', 'player_id')->map(fn ($v) => (int) $v)->all();
            $previousTeamValue = $teamValue;

            return [
                'day' => $day,
                'team_value' => $teamValue,
                'team_value_change' => $teamValueChange,
                'team_value_change_pct' => ($teamValueChange !== null && $previousDayTeamValue > 0)
                    ? round($teamValueChange / $previousDayTeamValue * 100, 1)
                    : null,
                'player_count' => $playersForDay->count(),
                'players' => $players,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function player(Request $request, FantasyPlayer $player)
    {
        $history = $player->snapshots()
            ->orderBy('captured_at')
            ->get(['market_value', 'points', 'average_points', 'clause_value', 'captured_at']);

        return response()->json(['data' => $history]);
    }
}
