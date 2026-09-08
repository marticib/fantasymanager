<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FantasyPlayerResource;
use App\Http\Resources\FantasyTeamResource;
use App\Models\FantasyExternalTrend;
use App\Services\Recommendation\FantasyScoreService;
use App\Services\Recommendation\FantasySettingsService;
use App\Services\Recommendation\PlayerDecisionEngine;
use App\Services\Recommendation\PlayerTrendPresenter;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function show(Request $request, PlayerTrendPresenter $presenter, FantasySettingsService $settings, PlayerDecisionEngine $decisionEngine)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $rules = $settings->rules($account);
        $cash = (int) ($team->money ?? 0);
        $availableCapital = max(0, $cash - (int) $rules['minimum_cash_reserve']);
        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')->get()->keyBy('fantasy_player_id');
        $decisions = $decisionEngine->evaluateRoster($account);

        $players = $team->teamPlayers()->with('player')->get()
            ->map(function ($tp) use ($account, $externalTrends, $presenter, $decisions) {
                if (! $tp->player) {
                    return null;
                }

                $externalTrend = $externalTrends->get($tp->player->id);
                $p = $presenter->present($tp->player, $account, $externalTrend);
                $decision = $decisions->get($tp->player->id);

                return array_merge(
                    (new FantasyPlayerResource($tp->player))->resolve(),
                    [
                        'clauseValue' => $tp->clause_value,
                        'isLocked' => $tp->is_locked,
                        'isStarter' => $tp->is_starter,
                        'fantasyScore' => $p->score->total,
                        'confidence' => $p->score->confidence,
                        'trend' => $p->trend->toArray(),
                        'externalTrend' => $p->externalTrendPayload(),
                        'history' => $p->history,
                        'historySource' => $p->historySource,
                        // PlayerDecisionEngine's verdict — HOLD/SELL/LOCK_CLAUSE, chosen by
                        // comparing three independent scores rather than reacting to any
                        // single signal. Deliberately not the same as the account's
                        // fantasy_recommendations (dashboard) engine, which never reads
                        // external data; see PlayerDecisionEngine's docblock.
                        'action' => $decision?->action ?? 'HOLD',
                        'decision' => $decision?->toArray(),
                    ],
                );
            })
            ->filter()
            ->values();

        return response()->json([
            'team' => new FantasyTeamResource($team),
            'summary' => [
                'teamValue' => $team->team_value,
                'cash' => $cash,
                'availableCapital' => $availableCapital,
                'minimumCashReserve' => (int) $rules['minimum_cash_reserve'],
            ],
            'players' => $players,
        ]);
    }

    public function analysis(Request $request, FantasyScoreService $scoreService)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $players = $team->players()->get();
        $byPosition = $players->groupBy('position');

        $positions = collect(['GK', 'DF', 'MF', 'FW'])->mapWithKeys(function (string $position) use ($byPosition, $account, $scoreService) {
            $group = $byPosition->get($position, collect());
            $scores = $group->map(fn ($p) => $scoreService->compute($p, $account)->total);

            return [$position => [
                'count' => $group->count(),
                'averageScore' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
                'strongCount' => $scores->filter(fn ($s) => $s >= 70)->count(),
            ]];
        });

        $weakPositions = $positions->filter(fn ($p) => $p['strongCount'] < 2)->keys()->values();

        return response()->json([
            'byPosition' => $positions,
            'weakPositions' => $weakPositions,
            'totalPlayers' => $players->count(),
        ]);
    }

    public function lineup(Request $request, FantasyScoreService $scoreService)
    {
        $account = $this->currentAccount($request);
        $team = $account->activeTeam;

        if (! $team) {
            return response()->json(['message' => 'No active team selected yet. Select a league first.'], 404);
        }

        $ranked = $team->players()->get()->map(function ($player) use ($account, $scoreService) {
            $score = $scoreService->compute($player, $account);
            $status = strtolower((string) $player->status);

            return [
                'player' => (new FantasyPlayerResource($player))->resolve(),
                'fantasyScore' => $score->total,
                'availability' => in_array($status, ['injured', 'doubtful', 'sanctioned'], true) ? 'DUBTE' : 'OK',
            ];
        })->sortByDesc('fantasyScore')->values();

        // Simple 1-4-4-2-shaped XI purely from Fantasy Score ranking; a real
        // formation/tactics picker is a later phase (see README roadmap).
        $slots = ['GK' => 1, 'DF' => 4, 'MF' => 4, 'FW' => 2];
        $starters = collect();

        foreach ($slots as $position => $count) {
            $starters = $starters->merge(
                $ranked->where('player.position', $position)->take($count)
            );
        }

        $starterIds = $starters->pluck('player.id');
        $bench = $ranked->reject(fn ($row) => $starterIds->contains($row['player']['id']))->values();

        return response()->json([
            'starters' => $starters->values()->map(fn ($r) => array_merge($r, ['role' => 'TITULAR'])),
            'bench' => $bench->map(fn ($r) => array_merge($r, ['role' => $r['availability'] === 'DUBTE' ? 'DUBTE' : 'BANQUETA'])),
        ]);
    }
}
