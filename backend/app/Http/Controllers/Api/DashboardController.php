<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyStanding;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\TodayActionsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * The single most important screen: "QUÈ HE DE FER AVUI?". Reads live —
     * every action here comes straight from TodayActionsService, which is
     * itself only an aggregator over PlayerDecisionEngine (own roster) and
     * MarketBuyAnalysisService (market listings); nothing here waits for a
     * scheduled job or a persisted fantasy_recommendations row, so it's
     * always as fresh as the last sync. (fantasy_recommendations/
     * FantasyRecommendationEngine keep powering /recommendations,
     * /market/opportunities and the Trading screen — untouched, just no
     * longer this screen's data source.)
     */
    public function today(Request $request, FantasySettingsService $settings, TodayActionsService $todayActions)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;
        $league = $account->activeLeague;
        $rules = $settings->rules($account);

        $cash = (int) ($team->money ?? 0);
        $availableCapital = max(0, $cash - (int) $rules['minimum_cash_reserve']);

        return response()->json([
            'summary' => [
                'teamValue' => $team?->team_value,
                'teamValueDeltaWeek' => $team ? $this->teamValueDeltaSince($team->id, now()->subDays(7)) : null,
                'cash' => $cash,
                'availableCapital' => $availableCapital,
                'minimumCashReserve' => (int) $rules['minimum_cash_reserve'],
                ...$this->standingSummary($league?->id, $team?->id),
            ],
            'leagueName' => $league?->name,
            'currentMatchday' => $account->current_matchday,
            'hasTeamSelected' => (bool) $account->active_team_id,
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
            'today' => $team ? $todayActions->build($account) : null,
        ]);
    }

    /**
     * Reconstructed from fantasy_player_snapshots (see HistoryController) —
     * there is no separate team-value-history table, so this stays derived
     * rather than risking two sources of truth drifting apart.
     */
    private function teamValueDeltaSince(int $teamId, Carbon $since): ?int
    {
        $current = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->orderByDesc('captured_at')
            ->first();

        $past = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->where('captured_at', '<=', $since)
            ->orderByDesc('captured_at')
            ->get();

        if (! $current || $past->isEmpty()) {
            return null;
        }

        // Sum each roster player's most recent snapshot at/before $since —
        // the roster itself may have changed since then, so this compares
        // "value of today's squad" against "value of that squad back then"
        // only for players we can actually trace back that far.
        $pastByPlayer = $past->groupBy('fantasy_player_id')->map(fn ($rows) => $rows->first());

        $currentTotal = FantasyPlayerSnapshot::where('owner_team_id', $teamId)
            ->whereNotNull('market_value')
            ->where('captured_at', $current->captured_at)
            ->sum('market_value');

        $pastTotal = $pastByPlayer->sum('market_value');

        return $pastTotal > 0 ? $currentTotal - $pastTotal : null;
    }

    /**
     * @return array{leaguePosition: ?int, leaguePositionDeltaWeek: ?int}
     */
    private function standingSummary(?int $leagueId, ?int $teamId): array
    {
        if (! $leagueId || ! $teamId) {
            return ['leaguePosition' => null, 'leaguePositionDeltaWeek' => null];
        }

        $rows = FantasyStanding::where('fantasy_league_id', $leagueId)
            ->where('fantasy_team_id', $teamId)
            ->orderByDesc('captured_at')
            ->limit(2)
            ->get(['position', 'captured_at']);

        return [
            'leaguePosition' => $rows->first()?->position,
            // Positive = climbed (lower position number is better, so we invert the raw difference).
            'leaguePositionDeltaWeek' => $rows->count() > 1 ? $rows[1]->position - $rows[0]->position : null,
        ];
    }
}
