<?php

namespace Tests\Feature;

use App\Models\FantasyAccount;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClausePurchaseOrderControllerTest extends TestCase
{
    use RefreshDatabase;

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
        $myTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T1', 'name' => 'My Team', 'is_mine' => true]);
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T2', 'name' => 'Rival', 'is_mine' => false]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $myTeam->id])->save();

        $player = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Clause Target']);
        FantasyTeamPlayer::create([
            'fantasy_team_id' => $rivalTeam->id,
            'fantasy_player_id' => $player->id,
            'player_team_id' => 'PT-1',
            'clause_value' => 10_000_000,
        ]);

        return [$user, $player];
    }

    // createOrder() checks the clause live right after creating it (see
    // ClausePurchaseOrderService) — faked here as still locked so tests that
    // only care about the PENDING row's shape aren't affected by that check.
    private function fakeStillLocked(): void
    {
        Http::fake([
            '*/leagues/*/teams/*' => Http::response(['players' => [[
                'playerMaster' => ['id' => '1', 'name' => 'Clause Target', 'positionId' => 1, 'marketValue' => 6_000_000, 'points' => 10, 'averagePoints' => 1.0, 'playerStatus' => 'ok'],
                'buyoutClause' => 10_000_000,
                'playerTeamId' => 'PT-1',
                'buyoutClauseLockedEndTime' => now()->addDay()->toIso8601String(),
                'isShielded' => false,
            ]]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);
    }

    public function test_creates_lists_and_cancels_an_order(): void
    {
        [$user, $player] = $this->fixture();
        $this->fakeStillLocked();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $player->id]);
        $create->assertCreated();
        $create->assertJsonPath('status', 'PENDING');
        $create->assertJsonPath('clauseValueAtOrder', 10_000_000);

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/clause-orders');
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        $orderId = $create->json('id');
        $cancel = $this->actingAs($user, 'sanctum')->deleteJson("/api/clause-orders/{$orderId}");
        $cancel->assertOk();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_CANCELLED, FantasyClausePurchaseOrder::find($orderId)->status);
    }

    /** Spends real money if this ever breaks — must be airtight. */
    public function test_another_accounts_order_can_never_be_confirmed_or_cancelled(): void
    {
        [$user, $player] = $this->fixture();
        $this->fakeStillLocked();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $player->id])->json();

        $stranger = User::factory()->create();
        FantasyAccount::create(['user_id' => $stranger->id]);

        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/clause-orders/{$order['id']}")->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/clause-orders/{$order['id']}/confirm")->assertForbidden();

        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, FantasyClausePurchaseOrder::find($order['id'])->status);
    }

    /** The management list must show whether the clause is still locked right now, without any live API call. */
    public function test_index_includes_the_current_lock_context_from_the_last_sync(): void
    {
        [$user, $player] = $this->fixture();
        $this->fakeStillLocked();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $player->id])->json();

        FantasyTeamPlayer::where('fantasy_player_id', $player->id)->update([
            'clause_locked_until' => now()->addHours(5),
        ]);

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/clause-orders');
        $row = collect($list->json('data'))->firstWhere('id', $order['id']);

        $this->assertTrue($row['isLocked']);
        $this->assertNotNull($row['clauseLockedUntil']);
        $this->assertSame(10_000_000, $row['currentClauseValue']);
        $this->assertTrue($row['stillOnTargetTeam']);
    }

    /** A player sold/transferred away since the order was placed must show an honest null, never a stale guess. */
    public function test_index_reports_no_current_context_when_the_player_left_the_target_team(): void
    {
        [$user, $player] = $this->fixture();
        $this->fakeStillLocked();
        $order = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $player->id])->json();

        FantasyTeamPlayer::where('fantasy_player_id', $player->id)->delete();

        $list = $this->actingAs($user, 'sanctum')->getJson('/api/clause-orders');
        $row = collect($list->json('data'))->firstWhere('id', $order['id']);

        $this->assertNull($row['isLocked']);
        $this->assertNull($row['clauseLockedUntil']);
        $this->assertNull($row['currentClauseValue']);
        $this->assertFalse($row['stillOnTargetTeam']);
    }

    public function test_rejects_creating_an_order_for_a_player_not_owned_by_a_rival(): void
    {
        $user = User::factory()->create();
        $account = FantasyAccount::create(['user_id' => $user->id]);
        $league = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L1', 'name' => 'Test League']);
        $account->forceFill(['active_league_id' => $league->id])->save();
        $freePlayer = FantasyPlayer::create(['external_id' => uniqid(), 'name' => 'Free Agent']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/clause-orders', ['fantasy_player_id' => $freePlayer->id]);

        $response->assertStatus(422);
    }
}
