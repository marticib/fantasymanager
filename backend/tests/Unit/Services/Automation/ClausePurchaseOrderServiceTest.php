<?php

namespace Tests\Unit\Services\Automation;

use App\Models\FantasyAccount;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyLeague;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeam;
use App\Models\FantasyTeamPlayer;
use App\Models\User;
use App\Services\Automation\ClausePurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The app's first automated *write* action against the real LaLiga API
 * (paying a rival's buyout clause). Every scenario here is check against
 * Http::fake — nothing in this suite (or anywhere else) is allowed to hit
 * the real payClause endpoint, since that spends real in-game money.
 */
class ClausePurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function rosterSlot(string $id, string $name, int $clauseValue, string $playerTeamId, ?string $lockedUntil = null): array
    {
        return [
            'playerMaster' => [
                'id' => $id,
                'name' => $name,
                'positionId' => 1,
                'marketValue' => (int) ($clauseValue / 1.5),
                'points' => 40,
                'averagePoints' => 4.0,
                'playerStatus' => 'ok',
            ],
            'buyoutClause' => $clauseValue,
            'playerTeamId' => $playerTeamId,
            'buyoutClauseLockedEndTime' => $lockedUntil,
            'isShielded' => false,
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
        $myTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T1', 'name' => 'My Team', 'is_mine' => true]);
        $rivalTeam = FantasyTeam::create(['fantasy_league_id' => $league->id, 'external_id' => 'T2', 'name' => 'Rival Team', 'is_mine' => false]);
        $account->forceFill(['active_league_id' => $league->id, 'active_team_id' => $myTeam->id])->save();

        $player = FantasyPlayer::create(['external_id' => '99', 'name' => 'Clause Target']);
        $teamPlayer = FantasyTeamPlayer::create([
            'fantasy_team_id' => $rivalTeam->id,
            'fantasy_player_id' => $player->id,
            'player_team_id' => 'PT-99',
            'clause_value' => 10_000_000,
        ]);

        return [$account->fresh(), $league, $rivalTeam, $player, $teamPlayer];
    }

    public function test_creates_a_pending_order_snapshotting_the_current_clause_value(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);
        $this->assertSame(10_000_000, $order->clause_value_at_order);
        $this->assertSame('PT-99', $order->player_team_id);
        $this->assertSame($rivalTeam->id, $order->target_team_id);
    }

    public function test_rejects_a_player_owned_in_a_different_league(): void
    {
        [$account, , , $player] = $this->fixture();

        // Same player id, but the owning team belongs to an entirely
        // different league — the same cross-account leak class of bug
        // already fixed once in PlayerController; createOrder() must not
        // repeat it, since this one would spend real money.
        $strangerLeague = FantasyLeague::create(['fantasy_account_id' => $account->id, 'external_id' => 'L2', 'name' => 'Other League']);
        FantasyTeam::create(['fantasy_league_id' => $strangerLeague->id, 'external_id' => 'T3', 'name' => 'Other League Rival', 'is_mine' => false]);

        // Re-home the only rival ownership row into the other league so the
        // account's ACTIVE league has no rival ownership of this player at all.
        FantasyTeamPlayer::where('fantasy_player_id', $player->id)->delete();

        $this->expectException(InvalidArgumentException::class);
        app(ClausePurchaseOrderService::class)->createOrder($account, $player);
    }

    public function test_rejects_a_duplicate_active_order(): void
    {
        [$account, , , $player] = $this->fixture();
        $service = app(ClausePurchaseOrderService::class);
        $service->createOrder($account, $player);

        $this->expectException(InvalidArgumentException::class);
        $service->createOrder($account, $player);
    }

    public function test_still_locked_clause_stays_pending(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::fake([
            '*/leagues/*/teams/*' => Http::response([
                'players' => [$this->rosterSlot('99', 'Clause Target', 10_000_000, 'PT-99', now()->addDay()->toIso8601String())],
            ], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->last_checked_at);
    }

    public function test_unlocked_unchanged_price_executes_automatically(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::fake([
            '*/leagues/*/teams/*' => Http::response([
                'players' => [$this->rosterSlot('99', 'Clause Target', 10_000_000, 'PT-99', null)],
            ], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $fresh->status);
        $this->assertSame(10_000_000, $fresh->executed_clause_value);
        $this->assertNotNull($fresh->executed_at);
    }

    /** Insufficient funds (or any other real rejection) must surface LaLiga's own reason, never a bare "rejected (400)". */
    public function test_a_real_api_rejection_like_insufficient_funds_fails_with_the_actual_reason(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::fake([
            '*/leagues/*/teams/*' => Http::response([
                'players' => [$this->rosterSlot('99', 'Clause Target', 10_000_000, 'PT-99', null)],
            ], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['message' => 'Fons insuficients'], 400),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertSame('Fons insuficients', $fresh->error_message);
        $this->assertNull($fresh->executed_clause_value);
    }

    public function test_unlocked_risen_price_needs_confirmation_and_never_pays(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::fake([
            '*/leagues/*/teams/*' => Http::response([
                'players' => [$this->rosterSlot('99', 'Clause Target', 14_000_000, 'PT-99', null)],
            ], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION, $fresh->status);
        $this->assertSame(14_000_000, $fresh->pending_confirmation_clause_value);
    }

    public function test_confirm_and_execute_pays_the_confirmed_higher_price(): void
    {
        [$account, , , $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => 14_000_000,
        ]);

        Http::fake([
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $fresh->status);
        $this->assertSame(14_000_000, $fresh->executed_clause_value);
    }

    /** A rejected manual confirmation must fail the order gracefully, never throw out to the caller (the API's own payment page shouldn't 500). */
    public function test_confirm_and_execute_fails_the_order_instead_of_throwing_when_the_api_rejects_it(): void
    {
        [$account, , , $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => 14_000_000,
        ]);

        Http::fake([
            '*/league/*/buyout/*/pay*' => Http::response(['message' => 'Fons insuficients'], 400),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertSame('Fons insuficients', $fresh->error_message);
    }

    public function test_player_no_longer_on_the_target_team_fails_the_order(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::fake([
            '*/leagues/*/teams/*' => Http::response(['players' => []], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->error_message);
    }

    public function test_cancel_order_from_pending(): void
    {
        [$account, , , $player] = $this->fixture();
        $service = app(ClausePurchaseOrderService::class);
        $order = $service->createOrder($account, $player);

        $service->cancelOrder($order);

        $this->assertSame(FantasyClausePurchaseOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_rejects_an_already_executed_order(): void
    {
        [$account, , , $player] = $this->fixture();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_EXECUTED]);

        $this->expectException(InvalidArgumentException::class);
        app(ClausePurchaseOrderService::class)->cancelOrder($order);
    }
}
