<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunFantasySyncJob;
use App\Models\FantasyAlert;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyStanding;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\TeamValueDeltaService;
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
     * Everything the app-wide shell needs, kept to a few cheap queries since
     * every open tab polls it: league/matchday/last sync, whether a sync is
     * running right now, whether the LaLiga session needs reconnecting, the
     * navigation badges and the unread notification count. (It used to load
     * the whole today() payload — every recommendation engine — for 3 fields.)
     */
    public function header(Request $request)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;
        $teamId = $account->active_team_id;

        $salesWithOffers = 0;
        $myActiveBids = 0;

        if ($league) {
            FantasyMarketPlayer::where('fantasy_league_id', $league->id)
                ->where('is_on_market', true)
                ->get(['seller_team_id', 'raw_payload'])
                ->each(function (FantasyMarketPlayer $m) use ($teamId, &$salesWithOffers, &$myActiveBids) {
                    if ($teamId && $m->seller_team_id === $teamId && (int) ($m->raw_payload['numberOfOffers'] ?? 0) > 0) {
                        $salesWithOffers++;
                    }
                    if (data_get($m->raw_payload, 'bid.id')) {
                        $myActiveBids++;
                    }
                });
        }

        return response()->json([
            'leagueName' => $league?->name,
            'currentMatchday' => $account->current_matchday,
            'lastSyncedAt' => $account->last_synced_at?->toIso8601String(),
            'syncing' => RunFantasySyncJob::isRunning($account->id),
            'laligaSessionExpired' => $account->laliga_session_expired_at !== null || ! $account->hasValidTokens(),
            'badges' => [
                'sales' => $salesWithOffers,
                'market' => $myActiveBids,
                'clauses' => FantasyClausePurchaseOrder::where('fantasy_account_id', $account->id)
                    ->where('status', FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION)
                    ->count(),
            ],
            'unreadAlerts' => FantasyAlert::where('fantasy_account_id', $account->id)->whereNull('read_at')->count(),
        ]);
    }

    /**
     * "Since your last visit" summary for Avui: notifications raised since
     * then (grouped by kind) and how much the squad's value moved.
     */
    public function since(Request $request, TeamValueDeltaService $teamValueDelta)
    {
        $data = $request->validate(['since' => ['required', 'date']]);
        $account = $this->currentAccount($request);
        $since = Carbon::parse($data['since']);

        $alerts = FantasyAlert::where('fantasy_account_id', $account->id)
            ->where('created_at', '>', $since)
            ->latest()
            ->get(['type', 'title', 'link']);

        return response()->json([
            'since' => $since->toIso8601String(),
            'teamValueDelta' => $account->active_team_id ? $teamValueDelta->deltaSince($account->active_team_id, $since) : null,
            'alertCounts' => $alerts->countBy('type'),
            'latest' => $alerts->take(5)->values(),
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
