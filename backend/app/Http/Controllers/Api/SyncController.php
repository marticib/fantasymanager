<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunFantasySyncJob;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /**
     * Queues a full read-only sync (players, market, team, recommendations)
     * for the current account. Runs on the queue rather than inline so a
     * slow LaLiga response never turns into a request timeout — see
     * RunFantasySyncJob and QUEUE_CONNECTION in .env.
     */
    public function sync(Request $request)
    {
        $account = $this->currentAccount($request);

        if (! $account->hasValidTokens()) {
            return response()->json(['message' => 'Primer has de connectar el teu compte de LaLiga a Configuració.'], 422);
        }

        // Lets the header show real progress (see DashboardController::header())
        // until the queued job actually finishes, instead of a fixed spinner.
        RunFantasySyncJob::markRunning($account->id);
        RunFantasySyncJob::dispatch($account->id);

        return response()->json(['message' => 'Sincronització en curs.'], 202);
    }
}
