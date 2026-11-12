<?php

namespace App\Services\Sync;

use App\Models\FantasyAccount;
use App\Models\FantasyAlert;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyStanding;
use App\Models\FantasyTeam;
use App\Models\FantasyTransaction;
use App\Services\FantasyApi\FantasyLeagueService;
use App\Services\Notifications\AlertService;
use Illuminate\Support\Carbon;

/**
 * Mirrors the league's activity feed (`/leagues/{id}/activity/{page}`,
 * newest first, the whole season in a few pages) into fantasy_transactions:
 * every money movement between a team and the market or another team, plus
 * matchday rewards. Type ids confirmed live against the rosters they changed
 * (see FantasyTransaction::TYPE_*); anything else in the feed is skipped.
 *
 * Incremental: stops paging once it reaches entries it already has. Raises a
 * PLAYER_STOLEN notification when one of your players moves to a rival.
 */
class LeagueActivitySync
{
    private const TYPES = [
        31 => FantasyTransaction::TYPE_MARKET_BUY,
        33 => FantasyTransaction::TYPE_MARKET_SELL,
        1 => FantasyTransaction::TYPE_TRANSFER,
        6 => FantasyTransaction::TYPE_MATCHDAY_REWARD,
    ];

    private const MAX_PAGES = 60;

    /** Only fresh moves raise a notification — never a whole season's backlog on the first sync. */
    private const NOTIFY_WITHIN_HOURS = 48;

    public function __construct(
        private readonly FantasyLeagueService $leagueService,
        private readonly AlertService $alerts,
    ) {}

    /** @return int number of new transactions stored */
    public function sync(FantasyAccount $account): int
    {
        $league = $account->activeLeague;

        if (! $league) {
            return 0;
        }

        $teamByManager = $this->teamsByManager($account, $league);
        $known = FantasyTransaction::where('fantasy_league_id', $league->id)->whereNotNull('external_id')->pluck('external_id')->flip()->all();
        $created = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $rows = $this->leagueService->getActivity($account, $league->external_id, $page);

            if ($rows === []) {
                break;
            }

            $sawKnown = false;
            $sawNew = false;

            foreach ($rows as $dto) {
                $type = self::TYPES[$dto->activityTypeId] ?? null;

                if (! $type || ! $dto->externalId || $dto->amount === null) {
                    continue;
                }
                if (isset($known[$dto->externalId])) {
                    $sawKnown = true;

                    continue;
                }

                $known[$dto->externalId] = true;
                $sawNew = true;

                $buyerTeam = $dto->buyerExternalId !== null ? ($teamByManager[$dto->buyerExternalId] ?? null) : null;
                $sellerTeam = $dto->sellerExternalId !== null ? ($teamByManager[$dto->sellerExternalId] ?? null) : null;

                [$from, $to] = match ($type) {
                    FantasyTransaction::TYPE_MARKET_BUY => [null, $buyerTeam],
                    // user1 is the seller on a sale to the market.
                    FantasyTransaction::TYPE_MARKET_SELL => [$buyerTeam, null],
                    FantasyTransaction::TYPE_TRANSFER => [$sellerTeam, $buyerTeam],
                    FantasyTransaction::TYPE_MATCHDAY_REWARD => [null, $buyerTeam],
                };

                $created[] = FantasyTransaction::create([
                    'fantasy_account_id' => $account->id,
                    'fantasy_league_id' => $league->id,
                    'fantasy_player_id' => $dto->playerExternalId ? FantasyPlayer::where('external_id', $dto->playerExternalId)->value('id') : null,
                    'type' => $type,
                    'amount' => $dto->amount,
                    'from_team_id' => $from,
                    'to_team_id' => $to,
                    'occurred_at' => $dto->occurredAt ? Carbon::parse($dto->occurredAt)->utc() : null,
                    'external_id' => $dto->externalId,
                    'raw_payload' => $dto->raw,
                ]);
            }

            // Newest first: a page that only repeats what we have means the rest is older history we already stored.
            if ($sawKnown && ! $sawNew) {
                break;
            }
        }

        $this->notifyLostPlayers($account, $created);

        return count($created);
    }

    /**
     * LaLiga's activity feed names managers, not teams. The manager id of
     * every team comes with the standings; backfill any team still missing it
     * (yours from the account itself) before mapping.
     *
     * @return array<string, int> manager external id => fantasy_teams.id
     */
    private function teamsByManager(FantasyAccount $account, FantasyLeague $league): array
    {
        $teams = FantasyTeam::where('fantasy_league_id', $league->id)->get();

        if ($teams->contains(fn (FantasyTeam $t) => $t->manager_external_id === null)) {
            $latest = FantasyStanding::where('fantasy_league_id', $league->id)->max('captured_at');
            $managerByTeam = $latest
                ? FantasyStanding::where('fantasy_league_id', $league->id)->where('captured_at', $latest)->get()
                    ->mapWithKeys(fn (FantasyStanding $s) => [$s->fantasy_team_id => data_get($s->raw_payload, 'team.managerId')])
                : collect();

            foreach ($teams as $team) {
                $manager = $team->manager_external_id
                    ?? $managerByTeam->get($team->id)
                    ?? ($team->id === $account->active_team_id ? $account->fantasy_user_id : null);

                if ($manager !== null && $team->manager_external_id === null) {
                    $team->update(['manager_external_id' => (string) $manager]);
                }
            }
        }

        return $teams->filter(fn (FantasyTeam $t) => $t->manager_external_id !== null)
            ->mapWithKeys(fn (FantasyTeam $t) => [(string) $t->manager_external_id => $t->id])
            ->all();
    }

    /** @param  list<FantasyTransaction>  $created */
    private function notifyLostPlayers(FantasyAccount $account, array $created): void
    {
        $myTeamId = $account->active_team_id;

        foreach ($created as $tx) {
            if ($tx->type !== FantasyTransaction::TYPE_TRANSFER || ! $myTeamId || $tx->from_team_id !== $myTeamId) {
                continue;
            }
            if (! $tx->occurred_at || $tx->occurred_at->lt(now()->subHours(self::NOTIFY_WITHIN_HOURS))) {
                continue;
            }

            $player = $tx->player?->name ?? 'Un jugador teu';
            $rival = $tx->toTeam?->name ?? 'un rival';

            $this->alerts->notify(
                $account,
                FantasyAlert::TYPE_PLAYER_STOLEN,
                "{$player} ara és de {$rival}",
                sprintf('Ha passat a %s per %s € (clàusula o oferta acceptada).', $rival, number_format($tx->amount, 0, ',', '.')),
                ['link' => '/activity', 'severity' => FantasyAlert::SEVERITY_WARNING, 'player_id' => $tx->fantasy_player_id, 'dedupe' => 'lost-'.$tx->external_id],
            );
        }
    }
}
