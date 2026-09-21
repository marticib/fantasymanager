<?php

namespace App\Services\Trading;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyMarketSnapshot;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use Illuminate\Support\Carbon;

/**
 * How old each data source behind a Trading recommendation is, and a warning
 * for any older than its configured threshold (fantasy.trading.staleness_minutes).
 * A source with no timestamp at all is reported as stale (NO_DATA) — never as
 * fresh; an unknown age is not a good age.
 *
 * Timestamps come from what the syncs really write:
 * - league: latest roster snapshot of your own team (syncTeam), falling back
 *   to the account's last_synced_at;
 * - market: latest FantasyMarketSnapshot of the league;
 * - clauses: latest roster snapshot owned by a rival team (syncRivalRosters);
 * - external: latest FantasyExternalTrend.fetched_at (futbolfantasy scrape).
 */
class TradingFreshnessService
{
    /**
     * @return array{
     *     sources: array<string, array{lastUpdatedAt: ?string, ageMinutes: ?int, thresholdMinutes: int, stale: bool}>,
     *     warnings: list<array{source: string, code: string, message: string}>,
     *     hasStale: bool
     * }
     */
    public function assess(FantasyAccount $account): array
    {
        $league = $account->activeLeague;
        $team = $account->activeTeam;
        $thresholds = config('fantasy.trading.staleness_minutes');

        $stamps = [
            'league' => $this->leagueStamp($account, $team),
            'market' => $league ? $this->parse(FantasyMarketSnapshot::where('fantasy_league_id', $league->id)->max('captured_at')) : null,
            'clauses' => $league ? $this->clausesStamp($league->id) : null,
            'external' => $this->parse(FantasyExternalTrend::where('source', 'futbolfantasy')->max('fetched_at')),
        ];

        $labels = ['league' => 'La plantilla i la caixa', 'market' => 'El mercat', 'clauses' => 'Les clàusules dels rivals', 'external' => 'Les dades de FútbolFantasy'];
        $sources = [];
        $warnings = [];

        foreach ($stamps as $source => $stamp) {
            $age = $stamp ? (int) $stamp->diffInMinutes(now(), true) : null;
            $stale = $age === null || $age > $thresholds[$source];

            $sources[$source] = [
                'lastUpdatedAt' => $stamp?->toIso8601String(),
                'ageMinutes' => $age,
                'thresholdMinutes' => (int) $thresholds[$source],
                'stale' => $stale,
            ];

            if ($stale) {
                $warnings[] = [
                    'source' => $source,
                    'code' => $age === null ? 'NO_DATA' : 'STALE',
                    'message' => $age === null
                        ? "{$labels[$source]}: encara no hi ha cap sincronització."
                        : "{$labels[$source]} fa {$this->humanAge($age)} que no s'actualitza.",
                ];
            }
        }

        return ['sources' => $sources, 'warnings' => $warnings, 'hasStale' => $warnings !== []];
    }

    private function leagueStamp(FantasyAccount $account, ?FantasyTeam $team): ?Carbon
    {
        $snapshot = $team ? $this->parse(FantasyPlayerSnapshot::where('owner_team_id', $team->id)->max('captured_at')) : null;

        return $snapshot ?? ($account->last_synced_at ? Carbon::parse($account->last_synced_at) : null);
    }

    private function clausesStamp(int $leagueId): ?Carbon
    {
        $rivalIds = FantasyTeam::where('fantasy_league_id', $leagueId)->where('is_mine', false)->pluck('id');

        return $this->parse(FantasyPlayerSnapshot::whereIn('owner_team_id', $rivalIds)->max('captured_at'));
    }

    private function parse(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function humanAge(int $minutes): string
    {
        return $minutes < 120 ? "{$minutes} min" : round($minutes / 60).' h';
    }
}
