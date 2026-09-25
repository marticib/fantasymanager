<?php

namespace Tests\Unit\Services\Automation;

use App\Jobs\ProcessClauseOrderJob;
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
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The app's first automated *write* action against the real LaLiga API
 * (paying a rival's buyout clause). Every scenario here is checked against
 * Http::fake — nothing in this suite (or anywhere else) is allowed to hit
 * the real payClause endpoint, since that spends real in-game money.
 *
 * createOrder() itself now does a live check right after creating the row
 * (see the class docblock) — so, unlike before, EVERY successful createOrder()
 * call needs an Http::fake() already in place. Each test declares Http::fake()
 * EXACTLY ONCE: calling it a second time does NOT override an overlapping

 * pattern from the first call (confirmed against Laravel's real behavior —
 * the first-registered stub for a given pattern wins for the rest of the
 * test, even across separate fake() calls), so a two-phase scenario (order
 * created while locked, later found unlocked) uses Http::sequence() on the
 * roster endpoint within one fake() call instead of faking twice.
 *
 * Queue::fake() is set globally: with the test env's QUEUE_CONNECTION=sync,
 * a real dispatch()->delay() from schedulePreciseCheck() would run inline
 * right away (sync ignores delay), silently consuming an Http::sequence()
 * response meant for a later, explicit processPendingOrders()/confirmAndExecute()
 * call in the same test. The precise-check job's own behaviour is covered
 * separately below and in ProcessClauseOrderJobTest.
 */
class ClausePurchaseOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

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

    private function rosterResponse(int $clauseValue, ?string $lockedUntil): array
    {
        return ['players' => [$this->rosterSlot('99', 'Clause Target', $clauseValue, 'PT-99', $lockedUntil)]];
    }

    private function fakeLockedRoster(int $clauseValue = 10_000_000): void
    {
        Http::fake([
            '*/leagues/*/teams/*' => Http::response($this->rosterResponse($clauseValue, now()->addDay()->toIso8601String()), 200),
            '*' => Http::response(['data' => []], 200),
        ]);
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
        $this->fakeLockedRoster();

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);
        $this->assertSame(10_000_000, $order->clause_value_at_order);
        $this->assertSame('PT-99', $order->player_team_id);
        $this->assertSame($rivalTeam->id, $order->target_team_id);
    }

    /** The whole point of this session's fix: scheduling a purchase for an ALREADY unlocked clause must not wait for the next scheduled tick. */
    public function test_creating_an_order_for_an_already_unlocked_clause_executes_immediately(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::response($this->rosterResponse(10_000_000, null), 200),
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $order->status);
        $this->assertSame(10_000_000, $order->executed_clause_value);
        $this->assertNotNull($order->executed_at);
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
        $this->fakeLockedRoster();
        $service = app(ClausePurchaseOrderService::class);
        $service->createOrder($account, $player);

        $this->expectException(InvalidArgumentException::class);
        $service->createOrder($account, $player);
    }

    public function test_still_locked_clause_stays_pending(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();
        $this->fakeLockedRoster();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->last_checked_at);
    }

    /** The scheduled-job path: an order created while still locked, picked up once it later unlocks. */
    public function test_unlocked_unchanged_price_executes_automatically(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String())) // createOrder()'s immediate check: still locked
                ->push($this->rosterResponse(10_000_000, null)), // processPendingOrders(): now unlocked
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $fresh->status);
        $this->assertSame(10_000_000, $fresh->executed_clause_value);
        $this->assertNotNull($fresh->executed_at);
    }

    /** The whole point: don't rely on LaLiga's own rejection for this — check live cash before ever calling payClause(). */
    public function test_insufficient_cash_fails_before_ever_calling_the_api(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String()))
                ->push($this->rosterResponse(10_000_000, null)),
            '*/teams/*/money*' => Http::response(['money' => 4_000_000], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('No tens prou diners', $fresh->error_message);
        $this->assertNull($fresh->executed_clause_value);
    }

    /** Insufficient funds (or any other real rejection) must surface LaLiga's own reason, never a bare "rejected (400)". */
    public function test_a_real_api_rejection_like_insufficient_funds_fails_with_the_actual_reason(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String()))
                ->push($this->rosterResponse(10_000_000, null)),
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['message' => 'Fons insuficients'], 400),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertSame('Fons insuficients', $fresh->error_message);
        $this->assertNull($fresh->executed_clause_value);
    }

    public function test_unlocked_risen_price_needs_confirmation_and_never_pays(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String()))
                ->push($this->rosterResponse(14_000_000, null)),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION, $fresh->status);
        $this->assertSame(14_000_000, $fresh->pending_confirmation_clause_value);
    }

    public function test_confirm_and_execute_pays_the_confirmed_higher_price(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String())) // createOrder(): locked, unchanged
                ->push($this->rosterResponse(14_000_000, null)), // confirm: rebaselined at 14M, now unlocked
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => 14_000_000,
        ]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $fresh->status);
        $this->assertSame(14_000_000, $fresh->executed_clause_value);
    }

    /** "Donar l'ordre de pagar" (confirming) must also check live cash first, same as the automatic path. */
    public function test_confirming_with_insufficient_cash_fails_before_ever_calling_the_api(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String())) // createOrder(): locked, unchanged
                ->push($this->rosterResponse(14_000_000, null)), // confirm: rebaselined at 14M, now unlocked
            '*/teams/*/money*' => Http::response(['money' => 4_000_000], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => 14_000_000,
        ]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('No tens prou diners', $fresh->error_message);
    }

    /** A rejected manual confirmation must fail the order gracefully, never throw out to the caller (the API's own payment page shouldn't 500). */
    public function test_confirm_and_execute_fails_the_order_instead_of_throwing_when_the_api_rejects_it(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String())) // createOrder(): locked, unchanged
                ->push($this->rosterResponse(14_000_000, null)), // confirm: rebaselined at 14M, now unlocked
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['message' => 'Fons insuficients'], 400),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => 14_000_000,
        ]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertSame('Fons insuficients', $fresh->error_message);
    }

    /** The real production case: a rival raises the clause while it is still locked — the user must be asked right away, not at unlock. */
    public function test_a_rise_while_still_locked_asks_for_confirmation_immediately(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDays(3)->toIso8601String()))
                ->push($this->rosterResponse(13_000_000, now()->addDays(3)->toIso8601String())),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        app(ClausePurchaseOrderService::class)->processPendingOrders();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION, $fresh->status);
        $this->assertSame(13_000_000, $fresh->pending_confirmation_clause_value);
    }

    /** Confirming while the clause is still locked re-arms the order at the new price; it must not try to pay yet. */
    public function test_confirming_while_still_locked_rearms_the_order_without_paying(): void
    {
        [$account, , , $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDays(3)->toIso8601String()))
                ->push($this->rosterResponse(13_000_000, now()->addDays(3)->toIso8601String())),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION, 'pending_confirmation_clause_value' => 13_000_000]);

        app(ClausePurchaseOrderService::class)->confirmAndExecute($order);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $fresh->status);
        $this->assertSame(13_000_000, $fresh->clause_value_at_order);
        $this->assertNull($fresh->pending_confirmation_clause_value);
    }

    public function test_player_no_longer_on_the_target_team_fails_the_order(): void
    {
        [$account, , $rivalTeam, $player] = $this->fixture();

        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addDay()->toIso8601String()))
                ->push(['players' => []]),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        app(ClausePurchaseOrderService::class)->processPendingOrders();

        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->error_message);
    }

    public function test_cancel_order_from_pending(): void
    {
        [$account, , , $player] = $this->fixture();
        $this->fakeLockedRoster();
        $service = app(ClausePurchaseOrderService::class);
        $order = $service->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        $service->cancelOrder($order);

        $this->assertSame(FantasyClausePurchaseOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_rejects_an_already_executed_order(): void
    {
        [$account, , , $player] = $this->fixture();
        $this->fakeLockedRoster();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_EXECUTED]);

        $this->expectException(InvalidArgumentException::class);
        app(ClausePurchaseOrderService::class)->cancelOrder($order);
    }

    /** The whole point of this session's follow-up: a still-locked clause schedules a job timed to the real unlock instant, not just the next poll tick. */
    public function test_a_still_locked_clause_schedules_a_precise_check_for_the_reported_unlock_instant(): void
    {
        [$account, , , $player] = $this->fixture();
        $unlockAt = now()->addHours(6);
        Http::fake([
            '*/leagues/*/teams/*' => Http::response($this->rosterResponse(10_000_000, $unlockAt->toIso8601String()), 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);

        Queue::assertPushed(ProcessClauseOrderJob::class, function (ProcessClauseOrderJob $job) use ($order, $unlockAt) {
            if ($job->orderId !== $order->id) {
                return false;
            }
            $buffer = (float) config('fantasy.sync.clause_unlock_check_buffer_seconds');

            // delay() accepts a DateTimeInterface|int; Queueable stores it as-is.
            return abs($job->delay->diffInSeconds($unlockAt->copy()->addMilliseconds((int) round($buffer * 1000)))) <= 1;
        });
    }

    /** Re-observing the exact same unlock instant (every poll tick while still locked) must not pile up duplicate jobs. */
    public function test_repeated_polls_of_the_same_still_locked_clause_schedule_only_one_precise_check(): void
    {
        [$account, , , $player] = $this->fixture();
        $this->fakeLockedRoster(); // same unlockAt (now()->addDay()) on every call

        $service = app(ClausePurchaseOrderService::class);
        $order = $service->createOrder($account, $player); // 1st schedule, inside createOrder()
        $service->processPendingOrders(); // re-observes the same instant
        $service->processPendingOrders(); // and again

        Queue::assertPushed(ProcessClauseOrderJob::class, 1);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->fresh()->status);
    }

    /** A rival re-locking with a later deadline must reschedule for the NEW instant, not silently keep the stale one. */
    public function test_a_changed_unlock_instant_schedules_a_fresh_precise_check(): void
    {
        [$account, , , $player] = $this->fixture();
        $firstUnlock = now()->addHours(3);
        $secondUnlock = now()->addHours(9);
        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, $firstUnlock->toIso8601String()))
                ->push($this->rosterResponse(10_000_000, $secondUnlock->toIso8601String())),
            '*' => Http::response(['data' => []], 200),
        ]);

        $service = app(ClausePurchaseOrderService::class);
        $service->createOrder($account, $player);
        $service->processPendingOrders();

        Queue::assertPushed(ProcessClauseOrderJob::class, 2);
    }

    /** ProcessClauseOrderJob, run directly (as the queue worker would once the delay elapses), pays a clause that has unlocked in the meantime. */
    public function test_the_precise_check_job_pays_once_it_fires_after_the_real_unlock(): void
    {
        [$account, , , $player] = $this->fixture();
        Http::fake([
            '*/leagues/*/teams/*' => Http::sequence()
                ->push($this->rosterResponse(10_000_000, now()->addMinutes(10)->toIso8601String())) // createOrder(): still locked
                ->push($this->rosterResponse(10_000_000, null)), // the job's own live check: now unlocked
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);

        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_PENDING, $order->status);

        (new ProcessClauseOrderJob($order->id))->handle(app(ClausePurchaseOrderService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/league/L1/buyout/PT-99/pay'));
        $fresh = $order->fresh();
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $fresh->status);
        $this->assertSame(10_000_000, $fresh->executed_clause_value);
    }

    /** The job is a no-op once the order has already been handled by anything else (poll, manual confirm, cancel) — it never re-processes a settled order. */
    public function test_the_precise_check_job_does_nothing_if_the_order_is_no_longer_pending(): void
    {
        [$account, , , $player] = $this->fixture();
        $this->fakeLockedRoster();
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_CANCELLED]);

        (new ProcessClauseOrderJob($order->id))->handle(app(ClausePurchaseOrderService::class));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/buyout/'));
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_CANCELLED, $order->fresh()->status);
    }

    /** The job is a no-op for an order id that no longer exists (never crashes the queue worker). */
    public function test_the_precise_check_job_does_nothing_for_a_missing_order(): void
    {
        (new ProcessClauseOrderJob(999_999))->handle(app(ClausePurchaseOrderService::class));
        $this->addToAssertionCount(1); // reaching here without throwing is the assertion
    }

    /**
     * The core race this session's request is really about: the regular
     * poll and the precise post-unlock job both reaching execute() for the
     * same order around the same real-world instant must never pay the
     * clause twice. attempt()'s claim() (an atomic UPDATE ... WHERE
     * status = 'PENDING') is what decides a winner — simulated here by
     * calling attempt() twice in a row on the same freshly-unlocked order.
     */
    public function test_two_concurrent_triggers_for_the_same_newly_unlocked_order_never_pay_twice(): void
    {
        [$account, , , $player] = $this->fixture();
        Http::fake([
            '*/leagues/*/teams/*' => Http::response($this->rosterResponse(10_000_000, null), 200),
            '*/teams/*/money*' => Http::response(['money' => 99_000_000], 200),
            '*/league/*/buyout/*/pay*' => Http::response(['data' => ['success' => true]], 200),
            '*' => Http::response(['data' => []], 200),
        ]);
        $order = app(ClausePurchaseOrderService::class)->createOrder($account, $player);
        $this->assertSame(FantasyClausePurchaseOrder::STATUS_EXECUTED, $order->status);

        // The poll only ever selects PENDING orders, so simulate the second
        // trigger (the precisely-timed job) landing right after: it must see
        // the order is no longer PENDING and do nothing, exactly like
        // ProcessClauseOrderJob::handle() itself guards.
        app(ClausePurchaseOrderService::class)->attempt($order->fresh());

        $payClauseCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/buyout/'))->count();
        $this->assertSame(1, $payClauseCalls);
        $this->assertSame(10_000_000, $order->fresh()->executed_clause_value);
    }
}
