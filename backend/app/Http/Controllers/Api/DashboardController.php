<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyStanding;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\TeamValueDeltaService;
use App\Services\Recommendation\TodayActionsService;
use Illuminate\Http\Request;

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
    public function today(Request $request, FantasySettingsService $settings, TodayActionsService $todayActions, TeamValueDeltaService $teamValueDelta)
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
                'teamValueDeltaWeek' => $team ? $teamValueDelta->deltaSince($team->id, now()->subDays(7)) : null,
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
