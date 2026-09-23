<?php

namespace Tests\Unit\Services\Sync;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use App\Services\Sync\FantasySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for a real production bug: the own team's roster used
 * to be synced from /teams/{id}/lineup alone, which only returns players
 * currently placed in the matchday formation (starters + whatever's in the
 * bench slots). A squad player NOT slotted into that formation (extra squad
 * depth) was silently absent from it, which made syncTeamRoster() delete
 * them from fantasy_team_players every sync and undercount team_value.
 */
class FantasySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function playerSlot(string $id, string $name, int $marketValue): array
    {
        return [
            'playerMaster' => [
                'id' => $id,
                'name' => $name,
                'positionId' => 1,
                'marketValue' => $marketValue,
                'points' => 40,
                'averagePoints' => 4.0,
                'playerStatus' => 'ok',
            ],
            'buyoutClause' => $marketValue * 2,
        ];
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create([
            'user_id' => $user->id,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHours(12),
        ]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Test League']);
        $team = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T1', 'name' => 'My Team', 'is_mine' => true]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $team->id])->save();

        return [$account->fresh(), $team];
    }

    public function test_a_squad_player_outside_the_current_formation_is_kept_and_counted_in_team_value(): void
    {
        [$account, $team] = $this->fixture();

        Http::fake([
            '*/teams/*/lineup*' => Http::response([
                'formation' => [
                    'goalkeeper' => [$this->playerSlot('1', 'Starter GK', 10_000_000)],
                    'defender' => [],
                    'midfield' => [],
                    'striker' => [],
                    'bench' => [],
                ],
            ], 200),
            '*/teams/*/money*' => Http::response(['money' => 5_000_000], 200),
            '*/leagues/*/teams/*' => Http::response([
                'players' => [
                    $this->playerSlot('1', 'Starter GK', 10_000_000),
                    // Owned but not part of the current formation at all —
                    // the exact shape that used to vanish from the roster.
                    $this->playerSlot('2', 'Extra Squad Depth', 3_000_000),
                ],
            ], 200),
            '*/leagues/*/standing*' => Http::response(['data' => []], 200),
            '*/week/current*' => Http::response(['data' => ['week' => 5]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(FantasySyncService::class)->syncTeam($account);

        $rosterExternalIds = FantasyTeamPlayer::where('fantasy_team_id', $team->id)
            ->with('player')->get()->pluck('player.external_id')->sort()->values();

        $this->assertSame(['1', '2'], $rosterExternalIds->all());
        $this->assertSame(13_000_000, $team->fresh()->team_value);

        $starter = FantasyTeamPlayer::whereHas('player', fn ($q) => $q->where('external_id', '1'))->first();
        $extra = FantasyTeamPlayer::whereHas('player', fn ($q) => $q->where('external_id', '2'))->first();

        $this->assertTrue((bool) $starter->is_starter);
        $this->assertFalse((bool) $extra->is_starter);
    }

    /**
     * Regression coverage for a real production bug: LaLiga's
     * `buyoutClauseLockedEndTime` carries an explicit UTC offset (confirmed
     * live, e.g. "2026-10-07T10:47:25+02:00"), but Eloquent's `datetime`
     * cast does not normalize a Carbon instance's timezone before writing
     * it — it stores whatever offset the instance still carries, so the
     * *local* wall-clock digits ("10:47:25") were landing verbatim in a
     * column the rest of the app reads back as UTC, making every stored
     * clause unlock silently 1-2h late (DST-dependent). See
     * FantasySyncService::parseDate().
     */
    public function test_a_clause_lock_timestamp_with_an_explicit_utc_offset_is_stored_as_the_true_utc_instant(): void
    {
        [$account] = $this->fixture();
        $rival = FantasyTeam::create(['fantasy_league_id' => $account->activeLeague->id, 'external_id' => 'T2', 'name' => 'Rival', 'is_mine' => false]);

        $slot = $this->playerSlot('9', 'Locked Clause Player', 20_000_000);
        $slot['buyoutClauseLockedEndTime'] = '2026-10-07T10:47:25+02:00';
        $slot['playerTeamId'] = 'PT-9';
        $slot['isShielded'] = false;

        Http::fake([
            '*/leagues/*/teams/*' => Http::response(['players' => [$slot]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(FantasySyncService::class)->syncRivalRosters($account);

        $teamPlayer = FantasyTeamPlayer::whereHas('player', fn ($q) => $q->where('external_id', '9'))->first();

        $this->assertNotNull($teamPlayer->clause_locked_until);
        // The true UTC instant is 2h behind the +02:00 wall-clock digits —
        // asserting against the UTC constant, not a re-derived offset,
        // keeps this test honest about what "correct" means here.
        $this->assertSame('2026-10-07 08:47:25', $teamPlayer->clause_locked_until->utc()->toDateTimeString());
    }

    /** Same bug, same fix, on the market side — see FantasySyncService::parseDate(). */
    public function test_a_market_listing_expiry_with_an_explicit_utc_offset_is_stored_as_the_true_utc_instant(): void
    {
        [$account] = $this->fixture();

        Http::fake([
            '*/league/*/market*' => Http::response([[
                'id' => 'M1',
                'playerMaster' => ['id' => '5', 'name' => 'Listed Player', 'positionId' => 1, 'marketValue' => 8_000_000, 'points' => 20, 'averagePoints' => 3.0, 'playerStatus' => 'ok'],
                'salePrice' => 8_500_000,
                'expirationDate' => '2026-09-24T23:00:00+02:00',
            ]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(FantasySyncService::class)->syncMarket($account);

        $listing = FantasyMarketPlayer::whereHas('player', fn ($q) => $q->where('external_id', '5'))->first();

        $this->assertNotNull($listing->expires_at);
        $this->assertSame('2026-09-24 21:00:00', $listing->expires_at->utc()->toDateTimeString());
    }
}
