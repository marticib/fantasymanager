<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyTeamPlayer;
use App\Models\FantasyTransaction;
use Illuminate\Support\Carbon;

/**
 * Your trading record from the league activity feed (fantasy_transactions):
 * every player you sold matched with what you paid for him the last time he
 * joined your team, the matchday rewards you earned, and the paper result of
 * the players you still hold. A player you never bought (league-start squad)
 * has no known cost: his sale is listed but never counted as profit.
 */
class TradeLedgerService
{
    private const BUY_TYPES = [FantasyTransaction::TYPE_MARKET_BUY, FantasyTransaction::TYPE_TRANSFER];

    private const SELL_TYPES = [FantasyTransaction::TYPE_MARKET_SELL, FantasyTransaction::TYPE_TRANSFER];

    /** @return array<string, mixed>|null null without an active team */
    public function build(FantasyAccount $account, ?Carbon $since = null): ?array
    {
        $teamId = $account->active_team_id;
        $leagueId = $account->active_league_id;

        if (! $teamId || ! $leagueId) {
            return null;
        }

        $txs = FantasyTransaction::where('fantasy_league_id', $leagueId)
            ->where(fn ($q) => $q->where('to_team_id', $teamId)->orWhere('from_team_id', $teamId))
            ->with(['player:id,name,image_url,position', 'fromTeam:id,name', 'toTeam:id,name'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $holding = []; // playerId => the buy that brought him in
        $closed = [];
        $rewards = [];

        foreach ($txs as $tx) {
            if ($tx->type === FantasyTransaction::TYPE_MATCHDAY_REWARD) {
                $rewards[] = $tx;

                continue;
            }

            if ($tx->to_team_id === $teamId && in_array($tx->type, self::BUY_TYPES, true)) {
                $holding[$tx->fantasy_player_id] = $tx;

                continue;
            }

            if ($tx->from_team_id === $teamId && in_array($tx->type, self::SELL_TYPES, true)) {
                $buy = $holding[$tx->fantasy_player_id] ?? null;
                unset($holding[$tx->fantasy_player_id]);
                $profit = $buy ? $tx->amount - $buy->amount : null;

                $closed[] = [
                    'player' => $this->player($tx),
                    'boughtFor' => $buy?->amount,
                    'boughtAt' => $buy?->occurred_at?->toIso8601String(),
                    'soldFor' => $tx->amount,
                    'soldAt' => $tx->occurred_at?->toIso8601String(),
                    'soldTo' => $tx->type === FantasyTransaction::TYPE_TRANSFER ? $tx->toTeam?->name : null,
                    'profit' => $profit,
                    'profitPct' => $buy && $buy->amount > 0 ? round($profit / $buy->amount * 100, 1) : null,
                    'daysHeld' => $buy && $buy->occurred_at && $tx->occurred_at ? (int) $buy->occurred_at->diffInDays($tx->occurred_at) : null,
                ];
            }
        }

        $closed = array_reverse($closed); // newest first
        $inWindow = fn (?string $at) => ! $since || ($at && Carbon::parse($at)->gte($since));
        $closedWindow = array_values(array_filter($closed, fn ($c) => $inWindow($c['soldAt'])));
        $known = array_values(array_filter($closedWindow, fn ($c) => $c['profit'] !== null));
        usort($known, fn ($a, $b) => $b['profit'] <=> $a['profit']);
        $rewardsWindow = array_filter($rewards, fn ($r) => ! $since || ($r->occurred_at && $r->occurred_at->gte($since)));

        return [
            'summary' => [
                'operations' => count($closedWindow),
                'realizedProfit' => $known ? array_sum(array_column($known, 'profit')) : null,
                'wins' => count(array_filter($known, fn ($c) => $c['profit'] > 0)),
                'losses' => count(array_filter($known, fn ($c) => $c['profit'] < 0)),
                'unknownCost' => count($closedWindow) - count($known),
                'best' => $known[0] ?? null,
                'worst' => $known ? $known[count($known) - 1] : null,
                'rewards' => (int) array_sum(array_map(fn ($r) => $r->amount, $rewardsWindow)),
                'rewardsCount' => count($rewardsWindow),
            ],
            'closed' => $closedWindow,
            'rewards' => array_values(array_map(fn ($r) => [
                'amount' => $r->amount,
                'week' => data_get($r->raw_payload, 'weekNumber'),
                'at' => $r->occurred_at?->toIso8601String(),
            ], array_reverse($rewardsWindow))),
            'open' => $this->openPositions($teamId),
        ];
    }

    /** Players you hold now: what you paid (when known) vs what they're worth today. */
    private function openPositions(int $teamId): array
    {
        return FantasyTeamPlayer::where('fantasy_team_id', $teamId)->with('player:id,name,image_url,position,market_value')->get()
            ->filter(fn ($e) => $e->player)
            ->map(fn ($e) => [
                'player' => ['id' => $e->player->id, 'name' => $e->player->name, 'imageUrl' => $e->player->image_url, 'position' => $e->player->position],
                'boughtFor' => $e->purchase_price,
                'boughtAt' => $e->acquired_at?->toIso8601String(),
                'value' => $e->player->market_value,
                'unrealized' => $e->purchase_price !== null && $e->player->market_value !== null ? $e->player->market_value - $e->purchase_price : null,
            ])
            ->sortByDesc(fn ($p) => $p['unrealized'] ?? PHP_INT_MIN)
            ->values()
            ->all();
    }

    private function player(FantasyTransaction $tx): ?array
    {
        $p = $tx->player;

        return $p ? ['id' => $p->id, 'name' => $p->name, 'imageUrl' => $p->image_url, 'position' => $p->position] : null;
    }
}
