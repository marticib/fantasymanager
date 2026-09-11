<?php

namespace Tests\Unit\Services\Recommendation;

use App\Models\FantasyAccount;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyPlayerSnapshot;
use App\Models\FantasyTeam;
use App\Models\User;
use App\Services\Recommendation\TeamValueDeltaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamValueDeltaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function team(): FantasyTeam
    {
        $account = FantasyAccount::create(['user_id' => User::factory()->create()->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => uniqid(), 'name' => 'L']);

        return FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => uniqid(), 'name' => 'My Team', 'is_mine' => true]);
    }

    private function snapshot(FantasyTeam $team, FantasyPlayer $player, int $marketValue, Carbon $capturedAt): void
    {
        FantasyPlayerSnapshot::create([
            'fantasy_player_id' => $player->id,
            'owner_team_id' => $team->id,
            'market_value' => $marketValue,
            'captured_at' => $capturedAt,
        ]);
    }

    public function test_computes_the_real_euro_and_percent_delta_between_now_and_a_past_point(): void
    {
        $team = $this->team();
        $p1 = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'P1', 'market_value' => 11_000_000]);
        $p2 = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'P2', 'market_value' => 9_000_000]);

        $this->snapshot($team, $p1, 10_000_000, now()->subDay());
        $this->snapshot($team, $p2, 10_000_000, now()->subDay());
        $this->snapshot($team, $p1, 11_000_000, now());
        $this->snapshot($team, $p2, 9_000_000, now());

        $result = (new TeamValueDeltaService)->since($team->id, now()->subDay());

        // 20,000,000 -> 20,000,000: net flat even though individual players moved.
        $this->assertSame(0, $result['delta']);
        $this->assertSame(20_000_000, $result['pastTotal']);
    }

    public function test_returns_null_when_no_snapshot_reaches_back_that_far(): void
    {
        $team = $this->team();
        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Just Connected', 'market_value' => 5_000_000]);
        $this->snapshot($team, $player, 5_000_000, now());

        $result = (new TeamValueDeltaService)->since($team->id, now()->subDay());

        $this->assertNull($result);
        $this->assertNull((new TeamValueDeltaService)->deltaSince($team->id, now()->subDay()));
    }

    public function test_a_sold_player_never_drags_a_stale_past_value_into_the_comparison(): void
    {
        $team = $this->team();
        $kept = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Kept', 'market_value' => 6_000_000]);
        $sold = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Sold Since', 'market_value' => 3_000_000]);

        $this->snapshot($team, $kept, 5_000_000, now()->subDay());
        $this->snapshot($team, $sold, 3_000_000, now()->subDay());
        $this->snapshot($team, $kept, 6_000_000, now());
        // $sold has no snapshot "now" (no longer on the roster) -> must be excluded from both sides, not just currentTotal.

        $result = (new TeamValueDeltaService)->since($team->id, now()->subDay());

        $this->assertSame(1_000_000, $result['delta']); // 6M now vs 5M (kept player only, not 8M with $sold's stale value mixed in)
        $this->assertSame(5_000_000, $result['pastTotal']);
    }
}
