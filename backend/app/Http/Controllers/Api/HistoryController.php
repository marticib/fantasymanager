<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyDailyReport;
use App\Models\FantasyDecisionSnapshot;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /** Default how far back the team-value chart goes when no range is given. */
    private const DEFAULT_WINDOW_DAYS = 30;

    private const RANGE_DAYS = ['7d' => 7, '30d' => 30, 'season' => null];

    /**
     * Team value over time, reconstructed from fantasy_player_snapshots for
     * the players side (no separate team-value table — the roster's own
     * history already carries this) and from fantasy_daily_reports for cash
     * (the only place fantasy_teams.money — a single, overwritten-every-sync
     * column — is ever recorded historically; see
     * FantasyGenerateRecommendationsCommand::recordDailyReport()). Never
     * reconstructs cash from anything else, and never guesses a day's cash
     * when no daily report exists for it.
     *
     * A sync can run several times a day, so a naive `SUM(market_value)
     * GROUP BY day` massively overcounts — it adds up every snapshot taken
     * that day, not one per player. This keeps only each player's latest
     * snapshot per day (via ROW_NUMBER, portable across the Postgres used
     * in production and the SQLite used in tests) before summing.
     */
    public function teamValue(Request $request)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['data' => [], 'summary' => null, 'message' => 'No active team selected yet.']);
        }

        $range = $request->query('range', '30d');
        $windowDays = array_key_exists($range, self::RANGE_DAYS) ? self::RANGE_DAYS[$range] : self::DEFAULT_WINDOW_DAYS;

        $ranked = FantasyPlayerSnapshot::query()
            ->where('owner_team_id', $team->id)
            ->when($windowDays !== null, fn ($q) => $q->where('captured_at', '>=', now()->subDays($windowDays)))
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

        $cashByDay = FantasyDailyReport::where('fantasy_account_id', $account->id)
            ->when($windowDays !== null, fn ($q) => $q->where('report_date', '>=', now()->subDays($windowDays)->toDateString()))
            ->get(['report_date', 'summary'])
            ->mapWithKeys(fn ($r) => [$r->report_date->toDateString() => $r->summary['cash'] ?? null]);

        $previousByPlayer = [];
        $previousTeamValue = null;
        $previousTotalValue = null;

        $data = $rows->groupBy('day')->map(function ($playersForDay, $day) use (&$previousByPlayer, &$previousTeamValue, &$previousTotalValue, $cashByDay) {
            $teamValue = (int) $playersForDay->sum('market_value');
            $previousDayTeamValue = $previousTeamValue;
            $teamValueChange = $previousDayTeamValue !== null ? $teamValue - $previousDayTeamValue : null;

            $cashBalance = $cashByDay->get($day);
            $totalValue = $cashBalance !== null ? $teamValue + $cashBalance : null;
            $previousDayTotalValue = $previousTotalValue;
            $totalValueChange = ($totalValue !== null && $previousDayTotalValue !== null) ? $totalValue - $previousDayTotalValue : null;

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
            $previousTotalValue = $totalValue ?? $previousTotalValue;

            return [
                'day' => $day,
                'players_value' => $teamValue,
                'cash_balance' => $cashBalance,
                'total_value' => $totalValue,
                'team_value' => $teamValue, // kept for backwards compatibility with existing consumers
                'team_value_change' => $teamValueChange,
                'team_value_change_pct' => ($teamValueChange !== null && $previousDayTeamValue > 0)
                    ? round($teamValueChange / $previousDayTeamValue * 100, 1)
                    : null,
                'total_value_change' => $totalValueChange,
                'total_value_change_pct' => ($totalValueChange !== null && $previousDayTotalValue > 0)
                    ? round($totalValueChange / $previousDayTotalValue * 100, 1)
                    : null,
                'player_count' => $playersForDay->count(),
                'players' => $players,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'summary' => $this->portfolioSummary($data),
        ]);
    }

    /**
     * The four top cards (VALOR INICIAL/ACTUAL/CREIXEMENT/ROI) — plain
     * arithmetic over the same series above (first vs last day that has a
     * total value), not a second calculation of anything. Falls back to
     * players-only value when no day in range has a cash figure yet, and
     * says so explicitly rather than showing a total that's silently wrong.
     */
    private function portfolioSummary($data): ?array
    {
        if ($data->isEmpty()) {
            return null;
        }

        $withTotal = $data->filter(fn ($d) => $d['total_value'] !== null);
        $usingTotal = $withTotal->isNotEmpty();
        $series = $usingTotal ? $withTotal : $data;
        $valueKey = $usingTotal ? 'total_value' : 'players_value';

        $first = $series->first();
        $last = $series->last();
        $initial = $first[$valueKey];
        $current = $last[$valueKey];
        $growth = $current - $initial;

        return [
            'initialValue' => $initial,
            'currentValue' => $current,
            'growth' => $growth,
            'roiPct' => $initial > 0 ? round($growth / $initial * 100, 2) : null,
            'firstRecordDate' => $first['day'],
            'lastRecordDate' => $last['day'],
            'includesCash' => $usingTotal,
        ];
    }

    public function player(Request $request, FantasyPlayer $player)
    {
        $history = $player->snapshots()
            ->orderBy('captured_at')
            ->get(['market_value', 'points', 'average_points', 'clause_value', 'captured_at']);

        return response()->json(['data' => $history]);
    }

    /**
     * Assistant backtesting summary — derived exclusively from
     * fantasy_decision_snapshots rows already graded by
     * fantasy:evaluate-decisions. Never counts PENDING/NOT_EVALUABLE
     * decisions as if they were resolved, and returns null (not 0%) when
     * nothing has been evaluated yet.
     */
    public function assistantPerformance(Request $request)
    {
        $account = $this->currentAccount($request);

        $base = FantasyDecisionSnapshot::where('fantasy_account_id', $account->id);

        $pendingCount = (clone $base)->where('status', FantasyDecisionSnapshot::STATUS_PENDING)->count();
        $notEvaluableCount = (clone $base)->where('status', FantasyDecisionSnapshot::STATUS_NOT_EVALUABLE)->count();
        $insufficientCount = (clone $base)->where('status', FantasyDecisionSnapshot::STATUS_INSUFFICIENT_DATA)->count();

        $evaluated = (clone $base)->where('status', FantasyDecisionSnapshot::STATUS_EVALUATED)->get(['action', 'outcome']);

        if ($evaluated->isEmpty()) {
            return response()->json([
                'evaluatedCount' => 0,
                'pendingCount' => $pendingCount,
                'notEvaluableCount' => $notEvaluableCount,
                'insufficientDataCount' => $insufficientCount,
                'accuracyPct' => null,
                'theoreticalProfit' => null,
                'averageRoiPct' => null,
            ]);
        }

        $favorable = $evaluated->filter(fn ($s) => ($s->outcome['favorable'] ?? null) === true)->count();
        $gradable = $evaluated->filter(fn ($s) => ($s->outcome['favorable'] ?? null) !== null);

        // "Benefici teòric": the economic impact each decision's own
        // methodology produces (realProfit for BUY/PAY_CLAUSE, avoidedLoss
        // for SELL/DO_NOT_CHASE) — never mixed with plain value growth.
        $theoreticalProfit = $evaluated->sum(fn ($s) => $s->outcome['realProfit'] ?? $s->outcome['avoidedLoss'] ?? 0);

        $rois = $evaluated->pluck('outcome.realRoi')->filter(fn ($v) => $v !== null);

        return response()->json([
            'evaluatedCount' => $evaluated->count(),
            'pendingCount' => $pendingCount,
            'notEvaluableCount' => $notEvaluableCount,
            'insufficientDataCount' => $insufficientCount,
            'accuracyPct' => $gradable->isNotEmpty() ? round(($favorable / $gradable->count()) * 100, 1) : null,
            'theoreticalProfit' => (int) round($theoreticalProfit),
            'averageRoiPct' => $rois->isNotEmpty() ? round($rois->avg() * 100, 1) : null,
        ]);
    }

    /**
     * Paginated decision history for the audit list — filters mirror the
     * columns actually stored (`action`, `status`), plus a `trade_only`
     * shortcut for TRADE_PROFIT-motivated sells (payload.sellReasonCode),
     * since "TRADE" isn't its own action code, it's a reason a SELL happens.
     */
    public function decisions(Request $request)
    {
        $account = $this->currentAccount($request);

        $query = FantasyDecisionSnapshot::where('fantasy_account_id', $account->id)
            ->with('player:id,name,club_name,position,image_url')
            ->orderByDesc('snapshot_date');

        if ($action = $request->query('action')) {
            $query->whereIn('action', explode(',', $action));
        }

        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }

        if ($request->boolean('trade_only')) {
            // Laravel's `column->json_path` where-syntax compiles to the
            // right JSON operator per driver (jsonb ->> on Postgres,
            // json_extract on SQLite) — same portability the rest of this
            // controller already relies on, see teamValue()'s docblock.
            $query->where('payload->sellReasonCode', 'TRADE_PROFIT');
        }

        if ($outcome = $request->query('outcome')) {
            // favorable / unfavorable, only meaningful for EVALUATED rows.
            $query->where('status', FantasyDecisionSnapshot::STATUS_EVALUATED)
                ->where('outcome->favorable', $outcome === 'favorable');
        }

        if ($range = $request->query('range')) {
            $days = self::RANGE_DAYS[$range] ?? null;
            if ($days !== null) {
                $query->where('snapshot_date', '>=', Carbon::now()->subDays($days)->toDateString());
            }
        }

        $snapshots = $query->paginate(min((int) $request->query('per_page', 20), 100));

        $snapshots->getCollection()->transform(fn (FantasyDecisionSnapshot $s) => $this->decisionResource($s));

        return response()->json($snapshots);
    }

    public function decision(Request $request, FantasyDecisionSnapshot $decision)
    {
        $account = $this->currentAccount($request);

        if ($decision->fantasy_account_id !== $account->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $decision->load('player:id,name,club_name,position,image_url');

        return response()->json($this->decisionResource($decision, includePayload: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionResource(FantasyDecisionSnapshot $s, bool $includePayload = false): array
    {
        $resource = [
            'id' => $s->id,
            'date' => $s->snapshot_date->toDateString(),
            'decisionType' => $s->decision_type,
            'action' => $s->action,
            'player' => $s->player ? [
                'id' => $s->player->id,
                'name' => $s->player->name,
                'club' => $s->player->club_name,
                'position' => $s->player->position,
                'imageUrl' => $s->player->image_url,
            ] : null,
            'referenceValue' => $s->reference_value,
            'currentMarketValue' => $s->current_market_value,
            'projectedValue' => $s->projected_value,
            'mainScore' => $s->main_score,
            'confidence' => $s->confidence,
            'horizonDays' => $s->horizon_days,
            'algorithmVersion' => $s->algorithm_version,
            'status' => $s->status,
            'outcome' => $s->outcome,
            'generatedAt' => $s->generated_at->toIso8601String(),
            'evaluatedAt' => $s->evaluated_at?->toIso8601String(),
        ];

        if ($includePayload) {
            $resource['payload'] = $s->payload;
        }

        return $resource;
    }
}
