<?php

namespace App\Services\Automation;

use App\Models\FantasyAccount;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeamPlayer;
use App\Services\FantasyApi\Exceptions\FantasyApiException;
use App\Services\FantasyApi\FantasyClauseService;
use App\Services\FantasyApi\FantasyTeamService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The app's first automated *write* action against the real LaLiga API: a
 * user schedules paying a rival's buyout clause the moment it unlocks.
 *
 * processOrder() (the shared check-and-maybe-pay step) runs from two places:
 * createOrder() itself (immediately, so scheduling an order for a clause
 * that's ALREADY unlocked doesn't sit there for up to
 * fantasy.sync.clause_orders_frequency_minutes doing nothing) and
 * processPendingOrders() (run on a schedule, see routes/console.php, for
 * orders that were still locked at creation time). Nothing else in this
 * codebase invokes a write endpoint automatically. Both always re-check live
 * (never trust the slower fantasy:sync-clauses snapshot, see
 * FantasySyncService) whether the clause is still locked and what it
 * currently costs, since racing other managers is the whole point:
 *   - still locked -> leave PENDING, try again next run.
 *   - unlocked, price unchanged or lower -> pay it automatically.
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
        try {
            $this->processOrder($order);
        } catch (Throwable $e) {
            $this->markFailed($order, $e);
        }

        return $order->fresh();
    }

    public function cancelOrder(FantasyClausePurchaseOrder $order): void
    {
        if (! in_array($order->status, [FantasyClausePurchaseOrder::STATUS_PENDING, FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION], true)) {
            throw new InvalidArgumentException('Only a pending or needs-confirmation order can be cancelled.');
        }

        $order->update(['status' => FantasyClausePurchaseOrder::STATUS_CANCELLED]);
    }

    public function confirmAndExecute(FantasyClausePurchaseOrder $order): void
    {
        if ($order->status !== FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION || $order->pending_confirmation_clause_value === null) {
            throw new InvalidArgumentException('Only an order awaiting confirmation can be confirmed.');
        }

        try {
            $this->execute($order, $order->pending_confirmation_clause_value);
        } catch (Throwable $e) {
            $this->markFailed($order, $e);
        }
    }

    public function processPendingOrders(): void
    {
        FantasyClausePurchaseOrder::where('status', FantasyClausePurchaseOrder::STATUS_PENDING)
            ->with(['account', 'league', 'targetTeam'])
            ->get()
            ->each(function (FantasyClausePurchaseOrder $order) {
                try {
                    $this->processOrder($order);
                } catch (Throwable $e) {
                    $this->markFailed($order, $e);
                }
            });
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

        if ($isLocked) {
            return;
        }

        $currentClauseValue = (int) ($entry->player->clauseValue ?? 0);

        if ($currentClauseValue <= 0) {
            $order->update(['status' => FantasyClausePurchaseOrder::STATUS_FAILED, 'error_message' => 'Current clause value unavailable.']);

            return;
        }

        if ($currentClauseValue <= $order->clause_value_at_order) {
            $this->execute($order, $currentClauseValue);

            return;
        }

        $order->update([
            'status' => FantasyClausePurchaseOrder::STATUS_NEEDS_CONFIRMATION,
            'pending_confirmation_clause_value' => $currentClauseValue,
        ]);
    }

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
