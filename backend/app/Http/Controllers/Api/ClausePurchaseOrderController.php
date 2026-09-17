<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyClausePurchaseOrder;
use App\Models\FantasyPlayer;
use App\Services\Automation\ClausePurchaseOrderService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ClausePurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->currentAccount($request);

        $query = FantasyClausePurchaseOrder::where('fantasy_account_id', $account->id)->with('player', 'targetTeam');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->latest()->get()->map(fn ($o) => $this->present($o))]);
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

    private function present(FantasyClausePurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'player' => $order->player ? ['id' => $order->player->id, 'name' => $order->player->name] : null,
            'targetTeam' => $order->targetTeam ? ['id' => $order->targetTeam->id, 'name' => $order->targetTeam->name] : null,
            'status' => $order->status,
            'clauseValueAtOrder' => $order->clause_value_at_order,
            'pendingConfirmationClauseValue' => $order->pending_confirmation_clause_value,
            'executedClauseValue' => $order->executed_clause_value,
            'errorMessage' => $order->error_message,
            'lastCheckedAt' => $order->last_checked_at?->toIso8601String(),
            'executedAt' => $order->executed_at?->toIso8601String(),
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }
}
