<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyTeam;
use App\Models\FantasyTransaction;
use App\Services\Recommendation\TradeLedgerService;
use Illuminate\Http\Request;

/**
 * "Què han fet els rivals": the league's money movements (synced from the
 * activity feed by LeagueActivitySync) plus, per team, what it spent and
 * earned over the same window.
 */
class ActivityController extends Controller
{
    private const ALLOWED_DAYS = [1, 7, 30];

    public function index(Request $request)
    {
        $account = $this->currentAccount($request);
        $league = $account->activeLeague;
        $days = in_array((int) $request->query('days', 7), self::ALLOWED_DAYS, true) ? (int) $request->query('days', 7) : 7;

        if (! $league) {
            return response()->json(['data' => [], 'teams' => [], 'days' => $days, 'message' => 'Encara no has triat cap lliga.']);
        }

        $since = now()->subDays($days);
        $txs = FantasyTransaction::where('fantasy_league_id', $league->id)
            ->where('occurred_at', '>=', $since)
            ->with(['player:id,name,image_url,position', 'fromTeam:id,name,is_mine', 'toTeam:id,name,is_mine'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $team = fn (?FantasyTeam $t) => $t ? ['id' => $t->id, 'name' => $t->name, 'isMine' => (bool) $t->is_mine] : null;

        $items = $txs->take(300)->map(fn (FantasyTransaction $tx) => [
            'id' => $tx->id,
            'type' => $tx->type,
            'amount' => $tx->amount,
            'at' => $tx->occurred_at?->toIso8601String(),
            'week' => data_get($tx->raw_payload, 'weekNumber'),
            'player' => $tx->player ? ['id' => $tx->player->id, 'name' => $tx->player->name, 'imageUrl' => $tx->player->image_url, 'position' => $tx->player->position] : null,
            'from' => $team($tx->fromTeam),
            'to' => $team($tx->toTeam),
        ])->values();

        $teams = FantasyTeam::where('fantasy_league_id', $league->id)->get()->map(function (FantasyTeam $t) use ($txs) {
            $in = $txs->where('to_team_id', $t->id);
            $out = $txs->where('from_team_id', $t->id);
            $spent = (int) $in->whereIn('type', [FantasyTransaction::TYPE_MARKET_BUY, FantasyTransaction::TYPE_TRANSFER])->sum('amount');
            $earned = (int) $out->whereIn('type', [FantasyTransaction::TYPE_MARKET_SELL, FantasyTransaction::TYPE_TRANSFER])->sum('amount');
            $rewards = (int) $in->where('type', FantasyTransaction::TYPE_MATCHDAY_REWARD)->sum('amount');

            return [
                'id' => $t->id,
                'name' => $t->name,
                'isMine' => (bool) $t->is_mine,
                'spent' => $spent,
                'earned' => $earned,
                'rewards' => $rewards,
                'net' => $earned + $rewards - $spent,
                'signings' => $in->whereIn('type', [FantasyTransaction::TYPE_MARKET_BUY, FantasyTransaction::TYPE_TRANSFER])->count(),
                'fromRivals' => $in->where('type', FantasyTransaction::TYPE_TRANSFER)->count(),
                'lostToRivals' => $out->where('type', FantasyTransaction::TYPE_TRANSFER)->count(),
                'sales' => $out->where('type', FantasyTransaction::TYPE_MARKET_SELL)->count(),
            ];
        })->sortByDesc('spent')->values();

        return response()->json(['data' => $items, 'teams' => $teams, 'days' => $days, 'total' => $txs->count()]);
    }

    /** Your own record: closed trades, rewards, open positions. */
    public function trades(Request $request, TradeLedgerService $ledger)
    {
        $account = $this->currentAccount($request);
        $result = $ledger->build($account);

        if ($result === null) {
            return response()->json(['message' => 'Encara no tens cap equip seleccionat.'], 404);
        }

        return response()->json($result);
    }
}
