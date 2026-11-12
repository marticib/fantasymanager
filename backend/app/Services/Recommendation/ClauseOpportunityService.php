<?php

namespace App\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeamPlayer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Assembles the Clauses screen's rows: for every rival-owned player with a
 * clause in the active league, combines roster context (owner, lock/shield
 * state, whether you can currently afford it) with the purely economic
 * verdict from ClauseEconomicAnalysisService — "would paying this clause
 * today pay for itself through the player's own value growth?"
 *
 * This only exists to assemble because FantasySyncService::syncRivalRosters()
 * pulls every rival team's roster (clause value, lock state) via the
 * league-scoped team endpoint — the direct lineup endpoint 403s for anyone
 * but your own team, so this data simply doesn't exist until that sync has
 * run at least once.
 *
 * Lock/shield state and available capital are informational only
 * (`isLocked`/`isShielded`/`daysUntilUnlock`/`affordable`) — never factored
 * into `economicRecommendation`. Both are temporary and can resolve before
 * you'd act on this anyway, so blocking on them would only hide a
 * genuinely good target rather than change whether it *is* one. Fantasy
 * Score is still returned per player (via PlayerTrendPresenter, shared with
 * Market/Team) purely as table context — it plays no part in the economic
 * verdict either; don't confuse `economicRecommendation` with the app's
 * sporting BUY/SELL/HOLD recommendations elsewhere.
 */
class ClauseOpportunityService
{
    private readonly PlayerValueTrendCalculator $trendCalculator;

    public function __construct(
        private readonly FantasySettingsService $settings,
        private readonly PlayerTrendPresenter $trendPresenter,
        private readonly ClauseEconomicAnalysisService $economicAnalysis,
        ?PlayerValueTrendCalculator $trendCalculator = null,
    ) {
        $this->trendCalculator = $trendCalculator ?? new PlayerValueTrendCalculator;
    }

    /**
     * @return Collection<int, array<string, mixed>> sorted by clauseEconomicScore desc
     */
    public function evaluate(FantasyAccount $account): Collection
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return collect();
        }

        $rules = $this->settings->rules($account);
        $cash = (int) ($team->money ?? 0);
        $availableCapital = max(0, $cash - (int) $rules['minimum_cash_reserve']);

        $rivalEntries = FantasyTeamPlayer::query()
            ->whereHas('team', fn ($q) => $q->where('fantasy_league_id', $league->id)->where('is_mine', false))
            ->whereNotNull('clause_value')
            ->with(['player', 'team'])
            ->get();

        $externalTrends = FantasyExternalTrend::where('source', 'futbolfantasy')
            ->whereIn('fantasy_player_id', $rivalEntries->pluck('fantasy_player_id'))
            ->get()
            ->keyBy('fantasy_player_id');

        return $rivalEntries
            ->map(fn (FantasyTeamPlayer $tp) => $this->evaluateEntry($tp, $account, $availableCapital, $externalTrends))
            ->filter()
            ->sortByDesc('clauseEconomicScore')
            ->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluateEntry(
        FantasyTeamPlayer $tp,
        FantasyAccount $account,
        int $availableCapital,
        Collection $externalTrends,
    ): ?array {
        $player = $tp->player;

        if (! $player || ! $player->market_value || ! $tp->clause_value) {
            return null;
        }

        $marketValue = (float) $player->market_value;
        $clauseValue = (float) $tp->clause_value;
        $externalTrend = $externalTrends->get($player->id);

        [$value1d, $value3d, $value7d] = $this->historicalValues($player, $marketValue, $externalTrend);

        $analysis = $this->economicAnalysis->analyze($marketValue, $clauseValue, $value1d, $value3d, $value7d);

        // Fantasy Score/history/sparkline in one call (shared with Market/Team
        // via PlayerTrendPresenter) — table context only, no bearing on the
        // economic verdict above.
        $presentation = $this->trendPresenter->present($player, $account, $externalTrend);

        $isLocked = $tp->clause_locked_until !== null && Carbon::parse($tp->clause_locked_until)->isFuture();
        $daysUntilUnlock = $isLocked ? (int) ceil(now()->diffInHours(Carbon::parse($tp->clause_locked_until)) / 24) : null;
        $isShielded = (bool) $tp->is_locked;
        $affordable = $clauseValue <= $availableCapital;

        return array_merge($analysis->toArray(), [
            'playerId' => $player->id,
            'playerName' => $player->name,
            'club' => $player->club_name,
            'position' => $player->position,
            'imageUrl' => $player->image_url,
            'ownerTeamId' => $tp->fantasy_team_id,
            'ownerTeamName' => $tp->team?->name,
            'fantasyScore' => $presentation->score->total,
            'isLocked' => $isLocked,
            'clauseLockedUntil' => $tp->clause_locked_until?->toIso8601String(),
            'daysUntilUnlock' => $daysUntilUnlock,
            'isShielded' => $isShielded,
            'affordable' => $affordable,
            'availableCapital' => $availableCapital,
            'trend' => $presentation->trend->toArray(),
            'externalTrend' => $presentation->externalTrendPayload(),
            'history' => $presentation->history,
            'historySource' => $presentation->historySource,
        ]);
    }

    /**
     * @return array{0: ?float, 1: ?float, 2: ?float} value 1d/3d/7d ago — see
     *                                                PlayerValueTrendCalculator::historicalValues() for how own-snapshot-vs-external
     *                                                preference and plausibility-guarding work.
     */
    private function historicalValues(FantasyPlayer $player, float $currentValue, ?FantasyExternalTrend $externalTrend): array
    {
        return $this->trendCalculator->historicalValues($player, $currentValue, $externalTrend);
    }
}
