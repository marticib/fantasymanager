<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyPlayer;
use App\Models\FantasyTeamPlayer;
use App\Services\Automation\ClausePurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ClausePurchaseOrderController extends Controller
{
    /**
     * Every order this account has ever placed — active (PENDING/
     * NEEDS_CONFIRMATION) and historical (EXECUTED/FAILED/CANCELLED) alike,
     * so this one endpoint is enough to both monitor and manage them (see
     * confirm()/destroy()); the frontend splits by status, this doesn't.
     * Each row also carries the *current* clause/lock state read from the
     * last synced fantasy_team_players row (not a live API call — an index
     * listing many orders doing that would be slow and pointless real-money
     * risk for a read-only view) so the UI can show "encara bloquejada,
     * falten Xh" without a second request per order.
     */
    public function index(Request $request)
    {
        $account = $this->currentAccount($request);

        $query = FantasyClausePurchaseOrder::where('fantasy_account_id', $account->id)->with('player', 'targetTeam');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->latest()->get();

        // One batched query for every order's current team-player row
        // instead of one per order — keyed by "team:player" since that pair
        // is the natural unique key here (FantasyTeamPlayer has no single-
        // column id we can whereIn against two columns with).
        $context = FantasyTeamPlayer::whereIn('fantasy_team_id', $orders->pluck('target_team_id')->unique())
            ->whereIn('fantasy_player_id', $orders->pluck('fantasy_player_id')->unique())
            ->get()
            ->keyBy(fn (FantasyTeamPlayer $tp) => "{$tp->fantasy_team_id}:{$tp->fantasy_player_id}");

        return response()->json([
            'data' => $orders->map(fn ($o) => $this->present($o, $context->get("{$o->target_team_id}:{$o->fantasy_player_id}")))->values(),
        ]);
    }

    public function store(Request $request, ClausePurchaseOrderService $orders)
    {
        $account = $this->currentAccount($request);
        $data = $request->validate(['fantasy_player_id' => 'required|integer|exists:fantasy_players,id']);
        $player = FantasyPlayer::findOrFail($data['fantasy_player_id']);

        try {
            $order = $orders->createOrder($account, $player);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($order), 201);
    }

    public function confirm(Request $request, FantasyClausePurchaseOrder $order, ClausePurchaseOrderService $orders)
    {
        $this->authorizeOwnership($request, $order);

        try {
            $orders->confirmAndExecute($order);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($order->fresh()));
    }

    public function destroy(Request $request, FantasyClausePurchaseOrder $order, ClausePurchaseOrderService $orders)
    {
        $this->authorizeOwnership($request, $order);

        try {
            $orders->cancelOrder($order);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Cancelled.']);
    }

    // A purchase order spends real money — ownership is checked explicitly
    // here rather than trusted from the URL, on top of route model binding.
    private function authorizeOwnership(Request $request, FantasyClausePurchaseOrder $order): void
    {
        if ($order->fantasy_account_id !== $this->currentAccount($request)->id) {
            abort(403);
        }
    }

    /**
     * $context (the order's own FantasyTeamPlayer row, as last synced — see
     * FantasySyncService::syncRivalRosters()) is optional so store()/confirm()
     * can call this on a single fresh order without needing index()'s
     * batched lookup; when omitted it's fetched here, one query, no N+1 risk
     * outside index()'s list case. Null (player sold/left the team since)
     * means the lock context fields below are honestly null, never guessed.
     */
    private function present(FantasyClausePurchaseOrder $order, false|FantasyTeamPlayer|null $context = false): array
    {
        if ($context === false) {
            $context = FantasyTeamPlayer::where('fantasy_team_id', $order->target_team_id)
                ->where('fantasy_player_id', $order->fantasy_player_id)
                ->first();
        }

        $isLocked = $context?->clause_locked_until !== null && Carbon::parse($context->clause_locked_until)->isFuture();

        return [
            'id' => $order->id,
            'player' => $order->player ? [
                'id' => $order->player->id,
                'name' => $order->player->name,
                'club' => $order->player->club_name,
                'position' => $order->player->position,
                'imageUrl' => $order->player->image_url,
            ] : null,
            'targetTeam' => $order->targetTeam ? ['id' => $order->targetTeam->id, 'name' => $order->targetTeam->name] : null,
            'status' => $order->status,
            'clauseValueAtOrder' => $order->clause_value_at_order,
            'pendingConfirmationClauseValue' => $order->pending_confirmation_clause_value,
            'executedClauseValue' => $order->executed_clause_value,
            'errorMessage' => $order->error_message,
            'lastCheckedAt' => $order->last_checked_at?->toIso8601String(),
            'executedAt' => $order->executed_at?->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
            // Context as of the last roster sync — never a live API call
            // from a list/read endpoint. isLocked/clauseLockedUntil null
            // (not false/never) when the player is no longer on that team's
            // roster at all, e.g. sold or transferred since the order was placed.
            'currentClauseValue' => $context?->clause_value,
            'isLocked' => $context ? $isLocked : null,
            'clauseLockedUntil' => $isLocked ? $context->clause_locked_until?->toIso8601String() : null,
            'isShielded' => $context?->is_locked,
            'stillOnTargetTeam' => $context !== null,
        ];
    }
}
