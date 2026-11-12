<?php

namespace App\Services\Recommendation;

use App\Models\FantasyPlayer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How a squad's market value has moved, for any team — yours or a rival's —
 * measured on the squad it has *today*: each player's own value history
 * (fantasy_player_snapshots, whoever owned him at the time), so a signing or
 * a sale never shows up as a "gain" or a "loss", only real value moves do.
 *
 * Unlike TeamValueDeltaService it doesn't depend on that team's own roster
 * syncs (rivals' rosters are synced far less often than players' values),
 * and every lookup is one indexed row per player and point in time — never a
 * player's whole history (thousands of rows each in production).
 */
class RosterValueHistoryService
{
    /**
     * How far back a snapshot may be and still stand for "the value at that
     * moment". Without it a gap in the history (syncs not running for days)
     * would make a weeks-old figure pass for yesterday's, and a weeks-long
     * move would be reported as one day's.
     */
    private const MAX_STALENESS_HOURS = 24;

    /**
     * Squad value now vs 24 hours ago, over the players that have a recent
     * value at both points (see MAX_STALENESS_HOURS).
     *
     * @param  Collection<int, FantasyPlayer>  $players
     * @return array{delta: int, pastTotal: int, pct: float}|null null when there's no history 24h back
     */
    public function delta24h(Collection $players): ?array
    {
        $since = now()->subDay();
        $current = 0;
        $past = 0;

        foreach ($players as $player) {
            $now = $player->valueSnapshotAtOrBefore(now(), now()->subHours(self::MAX_STALENESS_HOURS));
            $before = $player->valueSnapshotAtOrBefore($since, $since->copy()->subHours(self::MAX_STALENESS_HOURS));

            if (! $now || ! $before) {
                continue;
            }

            $current += (int) $now->market_value;
            $past += (int) $before->market_value;
        }

        if ($past <= 0) {
            return null;
        }

        $delta = $current - $past;

        return ['delta' => $delta, 'pastTotal' => $past, 'pct' => round($delta / $past * 100, 2)];
    }

    /**
     * The squad's value at the end of each of the last `$days` days (oldest
     * first) and how much it moved that day. A player's value on a day is
     * his last snapshot taken that same day (LaLiga moves values once a
     * day); without one he's left out of that day, and a
     * day's change only compares players known on both days — so a missing
     * figure or a gap in the history never reads as a gain or a loss.
     *
     * @param  Collection<int, FantasyPlayer>  $players
     * @return list<array{day: string, value: int, change: ?int, changePct: ?float}>
     */
    public function daily(Collection $players, int $days = 7): array
    {
        // One extra day so the first day shown has a change too.
        $ends = collect(range($days, 0))->map(fn (int $ago) => Carbon::today()->subDays($ago)->endOfDay());

        $values = []; // [dayIndex][playerId] => value
        foreach ($players as $player) {
            foreach ($ends as $i => $end) {
                $at = $end->isFuture() ? now() : $end;
                $snapshot = $player->valueSnapshotAtOrBefore($at, $end->copy()->startOfDay());
                if ($snapshot) {
                    $values[$i][$player->id] = (int) $snapshot->market_value;
                }
            }
        }

        $rows = [];
        foreach ($ends as $i => $end) {
            if ($i === 0) {
                continue;
            }

            $today = $values[$i] ?? [];
            $yesterday = $values[$i - 1] ?? [];
            // Only players known on both days are compared.
            $common = array_intersect_key($today, $yesterday);
            $previous = array_sum(array_intersect_key($yesterday, $common));
            $change = $common ? array_sum($common) - $previous : null;

            $rows[] = [
                'day' => $end->toDateString(),
                'value' => array_sum($today),
                'change' => $change,
                'changePct' => $change !== null && $previous > 0 ? round($change / $previous * 100, 2) : null,
            ];
        }

        return $rows;
    }
}
