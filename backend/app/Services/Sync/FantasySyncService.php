<?php

namespace App\Services\Sync;

use App\Models\FantasyAccount;
use App\Models\FantasyClub;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyMarketSnapshot;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyStanding;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Services\FantasyApi\DTOs\FantasyPlayerDTO;
use App\Services\FantasyApi\FantasyLeagueService;
use App\Services\FantasyApi\FantasyMarketService;
use App\Services\FantasyApi\FantasyPlayerService;
use App\Services\FantasyApi\FantasyTeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orchestrates pulling data from LaLiga Fantasy (via the FantasyApi/*
 * services) into our own tables, always keeping history in the *_snapshots
 * tables rather than only overwriting current state. This is the one place
 * both the artisan sync commands and the interactive API controllers call
 * into, so "sync now" from the UI and the scheduled job behave identically.
 */
class FantasySyncService
{
    public function __construct(
        private readonly FantasyLeagueService $leagueService,
        private readonly FantasyTeamService $teamService,
        private readonly FantasyPlayerService $playerService,
        private readonly FantasyMarketService $marketService,
    ) {}

    /**
     * @return Collection<int, FantasyLeague>
     */
    public function syncLeagues(FantasyAccount $account): Collection
    {
        $dtos = $this->leagueService->getLeagues($account);

        return collect($dtos)->map(fn ($dto) => FantasyLeague::updateOrCreate(
            ['fantasy_account_id' => $account->id, 'external_id' => $dto->externalId],
            ['name' => $dto->name, 'mode' => $dto->mode, 'team_count' => $dto->teamCount, 'raw_payload' => $dto->raw],
        ));
    }

    /**
     * Marks a league as active for the account and resolves + upserts "my
     * team" within it. LaLiga's leagues payload does not consistently expose
     * "which team here is mine" under one stable key across seasons, so we
     * try several plausible candidates and fall back to the account's own
     * fantasy_user_id (true on platforms where userId doubles as teamId for
     * single-team accounts). If none of that resolves, the caller can pass
     * $teamExternalIdOverride explicitly (surfaced in the UI as a manual
     * "my team id" field so onboarding never gets stuck on this guess).
     */
    public function selectLeague(FantasyAccount $account, FantasyLeague $league, ?string $teamExternalIdOverride = null): FantasyTeam
    {
        $teamExternalId = $teamExternalIdOverride
            ?? data_get($league->raw_payload, 'team.id')
            ?? data_get($league->raw_payload, 'myTeam.id')
            ?? data_get($league->raw_payload, 'ownTeam.id')
            ?? data_get($league->raw_payload, 'teamId')
            ?? $account->fantasy_user_id;

        if (! $teamExternalId) {
            throw new RuntimeException(
                'Could not auto-detect your team in this league from the LaLiga payload. Pass team_external_id explicitly.'
            );
        }

        $teamDto = $this->teamService->getTeam($account, $league->external_id, (string) $teamExternalId);

        $team = FantasyTeam::updateOrCreate(
            ['fantasy_league_id' => $league->id, 'external_id' => (string) $teamExternalId],
            [
                'name' => $teamDto->name ?? 'El meu equip',
                'manager_name' => $teamDto->managerName,
                'is_mine' => true,
                'money' => $teamDto->money,
                'team_value' => $teamDto->teamValue,
                'raw_payload' => $teamDto->raw,
            ],
        );

        $account->forceFill([
            'active_league_id' => $league->id,
            'active_team_id' => $team->id,
        ])->save();

        return $team;
    }

    /**
     * The club master list ({id, name, shortName, badge}) — small (~40 rows)
     * and rarely changes mid-season, so it's refreshed as a cheap side
     * effect of the player catalog sync rather than needing its own command.
     */
    public function syncClubs(FantasyAccount $account): int
    {
        $clubs = $this->playerService->getClubs($account);

        foreach ($clubs as $dto) {
            FantasyClub::updateOrCreate(
                ['external_id' => $dto->externalId],
                ['name' => $dto->name, 'short_name' => $dto->shortName, 'badge_url' => $dto->badgeUrl],
            );
        }

        return count($clubs);
    }

    public function syncPlayers(FantasyAccount $account): int
    {
        try {
            $this->syncClubs($account);
        } catch (\Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.sync.clubs_failed', ['message' => $e->getMessage()]);
        }

        $players = $this->playerService->getAllPlayers($account);
        $clubNames = FantasyClub::pluck('name', 'external_id')->all();

        foreach ($players as $dto) {
            $player = $this->upsertPlayerFromDto($dto, $clubNames);

            FantasyPlayerSnapshot::create([
                'fantasy_player_id' => $player->id,
                'market_value' => $dto->marketValue,
                'points' => $dto->points,
                'average_points' => $dto->averagePoints,
                'owner_team_id' => null,
                'clause_value' => $dto->clauseValue,
                'captured_at' => now(),
            ]);
        }

        return count($players);
    }

    public function syncTeam(FantasyAccount $account): void
    {
        $team = $account->activeTeam;
        $league = $account->activeLeague;

        if (! $team || ! $league) {
            return;
        }

        $money = $this->teamService->getMoney($account, $team->external_id);
        $clubNames = FantasyClub::pluck('name', 'external_id')->all();
        $rosterPlayerIds = $this->syncTeamRoster($account, $team, $clubNames);

        try {
            $this->syncOwnClauseProtection($account, $team, $league);
        } catch (\Throwable $e) {
            // Supplementary enrichment only (clause lock/shield state) — never
            // block the rest of the sync (money/team value/matchday) on it.
            Log::channel('fantasy_api')->warning('fantasy.sync.own_clause_protection_failed', ['message' => $e->getMessage()]);
        }

        $teamValue = FantasyPlayer::whereIn('id', $rosterPlayerIds)->sum('market_value');

        $team->update(['money' => $money, 'team_value' => $teamValue]);

        $currentMatchday = null;
        try {
            $currentMatchday = $this->playerService->getCurrentWeek($account);
        } catch (\Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.sync.current_week_failed', ['message' => $e->getMessage()]);
        }

        $account->update(['last_synced_at' => now(), 'current_matchday' => $currentMatchday ?? $account->current_matchday]);

        $this->syncStandings($account, $league);
    }

    /**
     * Rival teams' rosters — never synced by syncTeam(), which only ever
     * looks at the account's own team. Needed for clause-opportunity scoring
     * (App\Services\Recommendation\ClauseOpportunityService): the clause
     * value of a player someone else owns only exists in *their* lineup
     * response, not anywhere in /players or the market. Standings are synced
     * first if the league has none yet, since that's what creates the rival
     * FantasyTeam rows to iterate (see syncStandings()).
     *
     * @return array{teams: int, players: int}
     */
    public function syncRivalRosters(FantasyAccount $account): array
    {
        $league = $account->activeLeague;

        if (! $league) {
            return ['teams' => 0, 'players' => 0];
        }

        if (! FantasyTeam::where('fantasy_league_id', $league->id)->exists()) {
            $this->syncStandings($account, $league);
        }

        $clubNames = FantasyClub::pluck('name', 'external_id')->all();
        $rivals = FantasyTeam::where('fantasy_league_id', $league->id)->where('is_mine', false)->get();
        $playerCount = 0;

        foreach ($rivals as $rival) {
            try {
                $rosterPlayerIds = $this->syncTeamRoster($account, $rival, $clubNames);
                $playerCount += count($rosterPlayerIds);
            } catch (\Throwable $e) {
                // One rival's lineup failing (private team, transient 4xx, ...)
                // shouldn't abort the rest of the league.
                Log::channel('fantasy_api')->warning('fantasy.sync.rival_roster_failed', [
                    'team_id' => $rival->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return ['teams' => $rivals->count(), 'players' => $playerCount];
    }

    /**
     * Buyout-clause lock/shield state for your *own* roster. The endpoint
     * syncTeamRoster() uses for the own team (`getLineup()`) never carries
     * `buyoutClauseLockedEndTime`/`isShielded` — confirmed live, same gap
     * documented on FantasyTeamService::getLineup() — only the league-scoped
     * endpoint (already used for rival rosters) exposes it, and it works
     * for your own team too. A supplementary pass rather than switching
     * syncTeamRoster() itself, since that response lacks `is_starter` for
     * this same team, which the lineup endpoint alone provides.
     */
    private function syncOwnClauseProtection(FantasyAccount $account, FantasyTeam $team, FantasyLeague $league): void
    {
        $entries = $this->teamService->getLeagueTeamRoster($account, $league->external_id, $team->external_id);

        foreach ($entries as $entry) {
            if (! $entry->player->externalId) {
                continue;
            }

            $player = FantasyPlayer::where('external_id', $entry->player->externalId)->first();

            if (! $player) {
                continue;
            }

            FantasyTeamPlayer::where('fantasy_team_id', $team->id)
                ->where('fantasy_player_id', $player->id)
                ->update([
                    'clause_locked_until' => $entry->clauseLockedUntil,
                    'is_locked' => $entry->isShielded ?? false,
                ]);
        }
    }

    /**
     * @param  array<string, string>  $clubNames
     * @return int[] fantasy_players.id of everyone currently on this team's roster
     */
    private function syncTeamRoster(FantasyAccount $account, FantasyTeam $team, array $clubNames): array
    {
        // The direct lineup endpoint 403s for anyone else's team (confirmed
        // live) — rivals' rosters can only be read through the league-scoped
        // endpoint, which also happens to be the only place buyout-clause
        // lock/shield state is exposed.
        $rosterEntries = $team->is_mine
            ? $this->teamService->getLineup($account, $team->external_id)
            : $this->teamService->getLeagueTeamRoster($account, $team->league->external_id, $team->external_id);

        $rosterPlayerIds = [];

        foreach ($rosterEntries as $entry) {
            $dto = $entry->player;

            if (! $dto->externalId) {
                continue;
            }

            $player = $this->upsertPlayerFromDto($dto, $clubNames);

            FantasyTeamPlayer::updateOrCreate(
                ['fantasy_team_id' => $team->id, 'fantasy_player_id' => $player->id],
                [
                    'clause_value' => $dto->clauseValue,
                    'is_starter' => $entry->isStarter,
                    'clause_locked_until' => $entry->clauseLockedUntil,
                    'is_locked' => $entry->isShielded ?? false,
                    'raw_payload' => $dto->raw,
                ],
            );

            FantasyPlayerSnapshot::create([
                'fantasy_player_id' => $player->id,
                'market_value' => $player->market_value,
                'points' => $player->points,
                'average_points' => $player->average_points,
                'owner_team_id' => $team->id,
                'clause_value' => $dto->clauseValue,
                'captured_at' => now(),
            ]);

            $rosterPlayerIds[] = $player->id;
        }

        FantasyTeamPlayer::where('fantasy_team_id', $team->id)
            ->whereNotIn('fantasy_player_id', $rosterPlayerIds ?: [0])
            ->delete();

        return $rosterPlayerIds;
    }

    public function syncMarket(FantasyAccount $account): int
    {
        $league = $account->activeLeague;

        if (! $league) {
            return 0;
        }

        $listings = $this->marketService->getMarket($account, $league->external_id);
        $clubNames = FantasyClub::pluck('name', 'external_id')->all();
        $seenPlayerIds = [];

        foreach ($listings as $dto) {
            if (! $dto->playerExternalId) {
                continue;
            }

            $player = FantasyPlayer::firstWhere('external_id', $dto->playerExternalId)
                ?? $this->upsertPlayerFromDto(FantasyPlayerDTO::fromArray(data_get($dto->raw, 'playerMaster', $dto->raw)), $clubNames);

            $sellerTeam = $dto->sellerTeamExternalId
                ? FantasyTeam::where('fantasy_league_id', $league->id)->where('external_id', $dto->sellerTeamExternalId)->first()
                : null;

            $expiresAt = $dto->expiresAt ? $this->parseDate($dto->expiresAt) : null;

            $marketPlayer = FantasyMarketPlayer::updateOrCreate(
                ['fantasy_league_id' => $league->id, 'fantasy_player_id' => $player->id],
                [
                    'seller_team_id' => $sellerTeam?->id,
                    'market_value' => $dto->marketValue ?? $player->market_value,
                    'asking_price' => $dto->askingPrice,
                    'expires_at' => $expiresAt,
                    'is_on_market' => true,
                    'raw_payload' => $dto->raw,
                ],
            );

            FantasyMarketSnapshot::create([
                'fantasy_league_id' => $league->id,
                'fantasy_player_id' => $player->id,
                'market_value' => $marketPlayer->market_value,
                'asking_price' => $marketPlayer->asking_price,
                'is_on_market' => true,
                'captured_at' => now(),
            ]);

            $seenPlayerIds[] = $player->id;
        }

        FantasyMarketPlayer::where('fantasy_league_id', $league->id)
            ->whereNotIn('fantasy_player_id', $seenPlayerIds ?: [0])
            ->update(['is_on_market' => false]);

        return count($listings);
    }

    private function syncStandings(FantasyAccount $account, FantasyLeague $league): void
    {
        try {
            $standings = $this->leagueService->getStanding($account, $league->external_id);
        } catch (\Throwable $e) {
            Log::channel('fantasy_api')->warning('fantasy.sync.standings_failed', ['message' => $e->getMessage()]);

            return;
        }

        foreach ($standings as $dto) {
            $team = FantasyTeam::where('fantasy_league_id', $league->id)->where('external_id', $dto->teamExternalId)->first();

            if ($team) {
                // Don't clobber the "is_mine" team's own richer name/data from
                // selectLeague(); only backfill a name for rivals we don't own.
                if (! $team->is_mine && $dto->teamName) {
                    $team->update(['name' => $dto->teamName]);
                }
            } else {
                $team = FantasyTeam::create([
                    'fantasy_league_id' => $league->id,
                    'external_id' => $dto->teamExternalId,
                    'name' => $dto->teamName ?? 'Equip desconegut',
                    'is_mine' => false,
                ]);
            }

            FantasyStanding::create([
                'fantasy_league_id' => $league->id,
                'fantasy_team_id' => $team->id,
                'position' => $dto->position ?? 0,
                'points' => $dto->points ?? 0,
                'matchday' => null,
                'raw_payload' => $dto->raw,
                'captured_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $clubNames  external_id => name, from fantasy_clubs — neither
     *                                            /players nor /teams/{id}/lineup embed a club name,
     *                                            only a bare teamId (see syncClubs()).
     */
    private function upsertPlayerFromDto(FantasyPlayerDTO $dto, array $clubNames = []): FantasyPlayer
    {
        $existing = FantasyPlayer::firstWhere('external_id', $dto->externalId);
        $clubName = $dto->clubName ?? ($dto->clubExternalId ? ($clubNames[$dto->clubExternalId] ?? null) : null);

        $attributes = array_filter([
            'name' => $dto->name,
            'nickname' => $dto->nickname,
            'club_name' => $clubName,
            'club_external_id' => $dto->clubExternalId,
            'position' => $dto->position,
            'image_url' => $dto->imageUrl,
            'status' => $dto->status,
            'points' => $dto->points,
            'average_points' => $dto->averagePoints,
            'market_value' => $dto->marketValue,
        ], fn ($value) => $value !== null);

        if ($dto->marketValue !== null && $existing?->market_value !== null) {
            $attributes['previous_market_value'] = $existing->market_value;
        }

        $attributes['raw_payload'] = $dto->raw;
        $attributes['last_synced_at'] = now();
        $attributes['name'] = $attributes['name'] ?? $existing?->name ?? ('Player '.$dto->externalId);

        return FantasyPlayer::updateOrCreate(['external_id' => $dto->externalId], $attributes);
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
