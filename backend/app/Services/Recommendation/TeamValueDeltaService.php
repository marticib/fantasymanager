<?php

namespace App\Services\Recommendation;

use App\Models\FantasyPlayerSnapshot;
use Illuminate\Support\Carbon;

/**
 * How much a team's total squad value has moved since some point in time —
 * reconstructed from fantasy_player_snapshots (there is no separate
 * team-value-history table, so this stays derived rather than risking two
 * sources of truth drifting apart). Shared by DashboardController (7-day
 * delta) and TeamController (24h delta) so neither keeps its own copy of
 * this reconstruction.
 */
class TeamValueDeltaService
{
    /**
     * @return array{delta: int, pastTotal: int}|null null when there's no snapshot reaching back that far
     */
    public function since(int $teamId, Carbon $since): ?array
    {
        $current = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->orderByDesc('captured_at')
            ->first();

        if (! $current) {
            return null;
        }

        $currentSnapshots = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->where('captured_at', $current->captured_at)
            ->get(['fantasy_player_id', 'market_value']);

        // Restricted to today's roster: a player sold since $since would
        // otherwise drag a stale past value into the comparison with
        // nothing on the "now" side to net it against, and a player bought
        // since $since has no real baseline at all — neither is a genuine
        // market-value move, so both are left out of the past side rather
        // than distorting the delta.
        $past = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->whereIn('fantasy_player_id', $currentSnapshots->pluck('fantasy_player_id'))
            ->where('captured_at', '<=', $since)
            ->orderByDesc('captured_at')
            ->get();

        if ($past->isEmpty()) {
            return null;
        }

        $pastByPlayer = $past->groupBy('fantasy_player_id')->map(fn ($rows) => $rows->first());

        $currentTotal = (int) $currentSnapshots->sum('market_value');
        $pastTotal = (int) $pastByPlayer->sum('market_value');

        return $pastTotal > 0 ? ['delta' => $currentTotal - $pastTotal, 'pastTotal' => $pastTotal] : null;
    }

    public function deltaSince(int $teamId, Carbon $since): ?int
    {
        return $this->since($teamId, $since)['delta'] ?? null;
    }
}
