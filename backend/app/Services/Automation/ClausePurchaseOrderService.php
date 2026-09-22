<?php

namespace App\Services\Automation;

use App\Jobs\ProcessClauseOrderJob;
use App\Models\FantasyAccount;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeamPlayer;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\FantasyClauseService;
use App\Services\FantasyApi\FantasyTeamService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The app's first automated *write* action against the real LaLiga API: a
 * user schedules paying a rival's buyout clause the moment it unlocks.
 *
 * processOrder() (the shared check-and-maybe-pay step, always called through
 * attempt()) runs from three places: createOrder() itself (immediately, so
 * scheduling an order for a clause that's ALREADY unlocked doesn't sit there
 * doing nothing), processPendingOrders() (the regular poll, see
 * routes/console.php — the safety net, always running regardless of
 * anything else), and ProcessClauseOrderJob (a one-off job scheduled for the
 * clause's own reported unlock instant, see schedulePreciseCheck() — this is
 * what gets a payment attempt within seconds of the real unlock instead of
 * up to fantasy.sync.clause_orders_frequency_minutes minutes later). All
 * three always re-check live (never trust the slower fantasy:sync-clauses
 * snapshot, see FantasySyncService) whether the clause is still locked and
 * what it currently costs, since racing other managers is the whole point:
 *   - still locked -> leave PENDING, schedule a precise re-check for the
 *     reported unlock instant, and also try again on the next poll tick.
 *   - unlocked, price unchanged or lower -> pay it automatically (claim()
 *     makes sure only one of the poll/job pair that raced to this point
 *     actually pays).
 *   - unlocked, price risen -> NEEDS_CONFIRMATION, never spends without
 *     the user explicitly confirming the new price via confirmAndExecute().
 */
class ClausePurchaseOrderService
{
    public function __construct(
        private readonly FantasyTeamService $teamService,
        private readonly FantasyClauseService $clauseService,
    ) {}

    public function createOrder(FantasyAccount $account, FantasyPlayer $player): FantasyClausePurchaseOrder
    {
        $league = $account->activeLeague;

        if (! $league) {
            throw new InvalidArgumentException('No active league selected.');
        }

        $existing = FantasyClausePurchaseOrder::where('fantasy_account_id', $account->id)
            ->where('fantasy_player_id', $player->id)
            ->whereIn('status', [FantasyClausePurchaseOrder::STATUS_PENDING, FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION])
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('An order for this player is already active.');
        }

        // Same league-scoping discipline as PlayerController::show() — a
        // player owned in a *different* league entirely must never be
        // mistaken for the rival-owned one in the account's active league.
        $teamPlayer = FantasyTeamPlayer::where('fantasy_player_id', $player->id)
            ->whereHas('team', fn ($q) => $q->where('fantasy_league_id', $league->id)->where('is_mine', false))
            ->first();

        if (! $teamPlayer) {
            throw new InvalidArgumentException('This player is not owned by a rival in your active league.');
        }

        if ($teamPlayer->clause_value === null || $teamPlayer->player_team_id === null) {
            throw new InvalidArgumentException('Missing clause value or roster data for this player — try syncing first.');
        }

        $order = FantasyClausePurchaseOrder::create([
            'fantasy_account_id' => $account->id,
            'fantasy_league_id' => $league->id,
            'fantasy_player_id' => $player->id,
            'target_team_id' => $teamPlayer->fantasy_team_id,
            'player_team_id' => $teamPlayer->player_team_id,
            'clause_value_at_order' => $teamPlayer->clause_value,
            'status' => FantasyClausePurchaseOrder::STATUS_PENDING,
        ]);

        // If the clause is already unlocked right now, there's no reason to
        // make the user wait for the next fantasy:process-clause-orders tick
        // (up to FANTASY_SYNC_CLAUSE_ORDERS_FREQUENCY minutes later) — check
        // immediately, same logic the scheduled job uses.
        $this->attempt($order);

        return $order->fresh();
    }

    public function cancelOrder(FantasyClausePurchaseOrder $order): void
    {
        if (! in_array($order->status, [FantasyClausePurchaseOrder::STATUS_PENDING, FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION], true)) {
            throw new InvalidArgumentException('Only a pending or needs-confirmation order can be cancelled.');
        }

        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_CANCELLED]);
    }

    /**
     * Accepting a risen price re-arms the order at the new clause value —
     * it does not blindly pay: the clause may still be locked (rivals raise
     * clauses precisely during the lock), in which case the order simply
     * goes back to PENDING at the new baseline and pays on unlock, unless it
     * rises again (which asks again).
     */
    public function confirmAndExecute(FantasyClausePurchaseOrder $order): void
    {
        if ($order->status !== FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION || $order->pending_confirmation_clause_value === null) {
            throw new InvalidArgumentException('Only an order awaiting confirmation can be confirmed.');
        }

        $order->update([
            'clause_value_at_order' => $order->pending_confirmation_clause_value,
            'pending_confirmation_clause_value' => null,
            'status' => FantasyClausePurchaseOrder::STATUS_PENDING,
        ]);

        $this->attempt($order);
    }

    public function processPendingOrders(): void
    {
        FantasyClausePurchaseOrder::where('status', FantasyClausePurchaseOrder::STATUS_PENDING)
            ->with(['account', 'league', 'targetTeam'])
            ->get()
            ->each(fn (FantasyClausePurchaseOrder $order) => $this->attempt($order));
    }

    /**
     * The one entry point every trigger (createOrder(), confirmAndExecute(),
     * the regular poll, and ProcessClauseOrderJob's precisely-timed re-check)
     * goes through: processOrder() never throws out uncaught — any real
     * failure (API rejection, missing data, ...) always lands as a recorded
     * FAILED order, never an unhandled exception bubbling up to a caller
     * that didn't expect one (a scheduled command, a queued job, ...).
     */
    public function attempt(FantasyClausePurchaseOrder $order): void
    {
        try {
            $this->processOrder($order);
        } catch (Throwable $e) {
            $this->markFailed($order, $e);
        }
    }

    private function processOrder(FantasyClausePurchaseOrder $order): void
    {
        $account = $order->account;
        $league = $order->league;
        $targetTeam = $order->targetTeam;

        if (! $account || ! $league || ! $targetTeam) {
            $order->update(['status' => FantasyClausePurchaseOrder::STATUS_FAILED, 'error_message' => 'Account, league or target team no longer exists.']);

            return;
        }

        $rosterEntries = $this->teamService->getLeagueTeamRoster($account, $league->external_id, $targetTeam->external_id);
        $entry = collect($rosterEntries)->first(fn ($e) => $e->playerTeamId === $order->player_team_id);

        if (! $entry) {
            $order->update(['status' => FantasyClausePurchaseOrder::STATUS_FAILED, 'error_message' => 'El jugador ja no pertany a aquest equip.']);

            return;
        }

        $order->update(['last_checked_at' => now()]);

        $isLocked = $entry->clauseLockedUntil !== null && now()->lt($entry->clauseLockedUntil);
        $currentClauseValue = (int) ($entry->player->clauseValue ?? 0);

        // A rise is flagged as soon as it happens, locked or not — rivals
        // typically raise a clause *during* its lock, so waiting for the
        // unlock to compare prices meant the user was never asked.
        if ($currentClauseValue > $order->clause_value_at_order) {
            $order->update([
                'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
                'pending_confirmation_clause_value' => $currentClauseValue,
            ]);

            return;
        }

        if ($isLocked) {
            $this->schedulePreciseCheck($order, $entry->clauseLockedUntil);

            return;
        }

        if ($currentClauseValue <= 0) {
            $order->update(['status' => FantasyClausePurchaseOrder::STATUS_FAILED, 'error_message' => 'Current clause value unavailable.']);

            return;
        }

        $this->execute($order, $currentClauseValue);
    }

    /**
     * The clause unlocking exactly during the gap between two triggers is
     * the whole reason both exist (see schedulePreciseCheck()): the regular
     * poll and a precisely-timed ProcessClauseOrderJob can both land here
     * for the same order around the same real-world instant, both having
     * independently just read "unlocked, price OK" from the live roster.
     * claim() below is what stops that from paying the clause twice.
     */
    private function execute(FantasyClausePurchaseOrder $order, int $clauseValue): void
    {
        $account = $order->account;
        $league = $order->league;

        if (! $account || ! $league) {
            throw new RuntimeException('Account or league no longer exists.');
        }

        if (! $account->activeTeam) {
            throw new RuntimeException('No active team on this account.');
        }

        if (! $this->claim($order)) {
            // Another trigger already claimed and is (or already did) pay
            // this exact order — back off silently, this is not a failure.
            return;
        }

        // Checked live, same as the clause value/lock state in processOrder()
        // — fantasy_teams.money is only as fresh as the last fantasy:sync-team
        // run (every 30 min by default), which is nowhere near tight enough
        // right before spending real money.
        $availableMoney = $this->teamService->getMoney($account, $account->activeTeam->external_id);

        if ($availableMoney < $clauseValue) {
            throw new RuntimeException("No tens prou diners: calen {$clauseValue}, en tens {$availableMoney}.");
        }

        $this->clauseService->payClause($account, $league->external_id, $order->player_team_id, $clauseValue);

        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_EXECUTED,
            'executed_clause_value' => $clauseValue,
            'executed_at' => now(),
        ]);
    }

    /**
     * Atomically flips PENDING -> EXECUTING with a single UPDATE ... WHERE
     * status = 'PENDING' — the database, not application logic, decides
     * which of two concurrent callers wins: only the one whose UPDATE
     * actually matched a row gets to proceed to payClause(). The loser's
     * UPDATE matches 0 rows and returns false. A subsequent failure (API
     * rejection, insufficient funds, ...) still lands on markFailed() as
     * normal — EXECUTING -> FAILED is exactly as valid a transition as
     * PENDING -> FAILED was before this existed.
     */
    private function claim(FantasyClausePurchaseOrder $order): bool
    {
        return FantasyClausePurchaseOrder::where('id', $order->id)
            ->where('status', FantasyClausePurchaseOrder::STATUS_PENDING)
            ->update(['status' => FantasyClausePurchaseOrder::STATUS_EXECUTING]) === 1;
    }

    /**
     * Schedules the one-off precise re-check (see ProcessClauseOrderJob) for
     * a clause found still locked: fires `clause_unlock_check_buffer_seconds`
     * after the clause's own reported unlock instant, so a payment attempt
     * happens seconds — not up to `clause_orders_frequency_minutes` minutes —
     * after the real unlock, without waiting for the next poll tick.
     *
     * Cache::add() (atomic "set if absent") keyed by order + unlock instant
     * guards against scheduling a duplicate job every time processOrder()
     * re-observes the same still-locked clause (every poll tick, every
     * immediate check) — a second call for the *same* unlock instant is a
     * no-op; a *different* instant (a rival re-locking the clause with a
     * later deadline) schedules a fresh one, so this self-corrects if the
     * reported unlock time moves.
     */
    private function schedulePreciseCheck(FantasyClausePurchaseOrder $order, string $clauseLockedUntil): void
    {
        $unlockAt = Carbon::parse($clauseLockedUntil);

        if ($unlockAt->isPast()) {
            // Shouldn't happen (processOrder() only calls this when
            // $isLocked is true, i.e. clauseLockedUntil is in the future),
            // but never schedule a job for a moment that's already gone.
            return;
        }

        $cacheKey = "clause_order_precise_check:{$order->id}:{$unlockAt->timestamp}";

        if (! Cache::add($cacheKey, true, $unlockAt->copy()->addMinutes(2))) {
            return;
        }

        $bufferSeconds = (int) config('fantasy.sync.clause_unlock_check_buffer_seconds');
        ProcessClauseOrderJob::dispatch($order->id)->delay($unlockAt->copy()->addSeconds($bufferSeconds));
    }

    private function markFailed(FantasyClausePurchaseOrder $order, Throwable $e): void
    {
        $message = $this->describeFailure($e);

        // Recording the real failure on the order is the part that must
        // never be skipped — it comes before the (diagnostic-only) log call,
        // in case something about logging itself is broken (see the
        // 'fantasy_api' channel's own ignore_exceptions note in
        // config/logging.php — this is defense in depth on top of that,
        // not a substitute for it).
        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_FAILED, 'error_message' => $message]);

        Log::channel('fantasy_api')->warning('fantasy.clause_orders.process_failed', [
            'order_id' => $order->id,
            'message' => $message,
        ]);
    }

    // LaLiga's real payClause response shape is unconfirmed (never called
    // before this feature) — this surfaces whatever the API actually said
    // (e.g. insufficient funds) instead of a bare "rejected (400)", without
    // assuming a specific error-body key that might not exist.
    private function describeFailure(Throwable $e): string
    {
        if (! $e instanceof FantasyApiException || $e->responseBody === null) {
            return $e->getMessage();
        }

        $body = $e->responseBody;
        $reason = is_array($body)
            ? ($body['message'] ?? $body['error'] ?? $body['errorMessage'] ?? $body['detail'] ?? null)
            : null;

        return $reason ?? $e->getMessage().' — '.json_encode($body);
    }
}
