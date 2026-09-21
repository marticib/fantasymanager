<?php

namespace Tests\Concerns;

use App\Models\FantasyAccount;
use App\Models\FantasyExternalTrend;
use App\Models\FantasyLeague;
use App\Models\FantasyMarketPlayer;
use App\Models\FantasyMarketSnapshot;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared world-building for the Trading tests: a league with your team, plus
 * helpers to add the three kinds of candidate (own / market / clause) and the
 * futbolfantasy row that makes each one economically evaluable.
 */
trait BuildsTradingFixtures
{
    protected FantasyAccount $account;

    protected FantasyLeague $league;

    protected FantasyTeam $myTeam;

    protected User $user;

    protected function setUpTrading(int $cash = 50_000_000): void
    {
        config(['fantasy.trading.cache_seconds' => 0]);

        $this->user = User::factory()->create();
        $this->account = FantasyAccount::create(['user_id' => $this->user->id, 'last_synced_at' => now()]);
        $this->league = FantasyLeague::create(['fantasy_account_id' => $this->account->id, 'external_id' => uniqid(), 'name' => 'Test League']);
        $this->myTeam = FantasyTeam::create(['fantasy_league_id' => $this->league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true, 'money' => $cash]);
        $this->account->forceFill(['active_league_id' => $this->league->id, 'active_team_id' => $this->myTeam->id])->save();
        $this->account = $this->account->fresh();
    }

    protected function player(string $name, string $position, int $marketValue): FantasyPlayer
    {
        return FantasyPlayer::create([
            'external_id' => uniqid(),
            'name' => $name,
            'position' => $position,
            'market_value' => $marketValue,
            'average_points' => 5.0,
            'points' => 50,
            'status' => 'ok',
        ]);
    }

    /**
     * A futbolfantasy row. Defaults describe a steadily rising player whose
     * value equals `$now`; pass explicit windows (or null to leave one out).
     */
    protected function ff(FantasyPlayer $player, ?int $now = null, ?int $v1 = -1, ?int $v3 = -1, ?int $v7 = -1, array $extra = []): FantasyExternalTrend
    {
        $now ??= (int) $player->market_value;

        return FantasyExternalTrend::create(array_merge([
            'fantasy_player_id' => $player->id,
            'source' => 'futbolfantasy',
            'external_id' => uniqid(),
            'match_confidence' => 'exact',
            'value_now' => $now,
            'value_1d' => $v1 === -1 ? (int) round($now * 0.995) : $v1,
            'value_3d' => $v3 === -1 ? (int) round($now * 0.985) : $v3,
            'value_7d' => $v7 === -1 ? (int) round($now * 0.96) : $v7,
            'fetched_at' => now(),
        ], $extra));
    }

    /** A strongly rising futbolfantasy profile (~3%/day). */
    protected function ffRising(FantasyPlayer $player, array $extra = []): FantasyExternalTrend
    {
        $now = (int) $player->market_value;

        return $this->ff($player, $now, (int) round($now * 0.97), (int) round($now * 0.92), (int) round($now * 0.85), $extra);
    }

    /** A falling futbolfantasy profile. */
    protected function ffFalling(FantasyPlayer $player, array $extra = []): FantasyExternalTrend
    {
        $now = (int) $player->market_value;

        return $this->ff($player, $now, (int) round($now * 1.006), (int) round($now * 1.02), (int) round($now * 1.05), $extra);
    }

    protected function own(FantasyPlayer $player, bool $starter = false): FantasyTeamPlayer
    {
        return FantasyTeamPlayer::create(['fantasy_team_id' => $this->myTeam->id, 'fantasy_player_id' => $player->id, 'is_starter' => $starter]);
    }

    protected function listing(FantasyPlayer $player, ?int $marketValue = null, ?int $asking = null, array $payload = []): FantasyMarketPlayer
    {
        $marketValue ??= (int) $player->market_value;

        return FantasyMarketPlayer::create([
            'fantasy_league_id' => $this->league->id,
            'fantasy_player_id' => $player->id,
            'market_value' => $marketValue,
            'asking_price' => $asking ?? $marketValue,
            'is_on_market' => true,
            'expires_at' => now()->addDay(),
            'raw_payload' => $payload,
        ]);
    }

    protected function clause(FantasyPlayer $player, int $clauseValue, array $extra = []): FantasyTeamPlayer
    {
        $rival = FantasyTeam::where('fantasy_league_id', $this->league->id)->where('is_mine', false)->first()
            ?? FantasyTeam::create(['fantasy_league_id' => $this->league->id, 'external_id' => uniqid(), 'name' => 'Rival', 'is_mine' => false]);

        return FantasyTeamPlayer::create(array_merge(['fantasy_team_id' => $rival->id, 'fantasy_player_id' => $player->id, 'clause_value' => $clauseValue], $extra));
    }

    /** Make every source look freshly synced (no freshness warnings). */
    protected function markAllFresh(?Carbon $at = null): void
    {
        $at ??= now();
        $probe = $this->player('Sync Probe', 'GK', 1_000_000);
        $rival = FantasyTeam::where('fantasy_league_id', $this->league->id)->where('is_mine', false)->first()
            ?? FantasyTeam::create(['fantasy_league_id' => $this->league->id, 'external_id' => uniqid(), 'name' => 'Rival', 'is_mine' => false]);

        FantasyMarketSnapshot::create(['fantasy_league_id' => $this->league->id, 'fantasy_player_id' => $probe->id, 'market_value' => 1_000_000, 'is_on_market' => true, 'captured_at' => $at]);
        FantasyPlayerSnapshot::create(['fantasy_player_id' => $probe->id, 'owner_team_id' => $this->myTeam->id, 'market_value' => 1_000_000, 'captured_at' => $at]);
        FantasyPlayerSnapshot::create(['fantasy_player_id' => $probe->id, 'owner_team_id' => $rival->id, 'market_value' => 1_000_000, 'captured_at' => $at]);
        FantasyExternalTrend::where('source', 'futbolfantasy')->update(['fetched_at' => $at]);
    }

    /** Own roster that covers a whole 4-4-2 (and nothing else). */
    protected function ownFullSquad(): array
    {
        $players = [];
        foreach (['GK' => 2, 'DF' => 5, 'MF' => 5, 'FW' => 3] as $position => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $p = $this->player("Own {$position}{$i}", $position, 4_000_000);
                $this->own($p, true);
                $players[] = $p;
            }
        }

        return $players;
    }
}
