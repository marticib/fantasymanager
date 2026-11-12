<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyAlert;
use Illuminate\Http\Request;

/**
 * The in-app notifications behind the header bell (see AlertService for
 * what raises them).
 */
class AlertController extends Controller
{
    public function index(Request $request)
    {
        $account = $this->currentAccount($request);

        $alerts = FantasyAlert::where('fantasy_account_id', $account->id)
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (FantasyAlert $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'title' => $a->title,
                'message' => $a->message,
                'link' => $a->link,
                'severity' => $a->severity,
                'read' => $a->read_at !== null,
                'createdAt' => $a->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $alerts]);
    }

    public function read(Request $request, FantasyAlert $alert)
    {
        abort_unless($alert->fantasy_account_id === $this->currentAccount($request)->id, 404);

        $alert->update(['read_at' => $alert->read_at ?? now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        FantasyAlert::where('fantasy_account_id', $this->currentAccount($request)->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
