<?php

namespace App\Jobs;

use App\Models\FantasyClausePurchaseOrder;
use App\Services\Automation\ClausePurchaseOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A one-off, precisely-timed re-check of a single clause purchase order,
 * dispatched by ClausePurchaseOrderService::schedulePreciseCheck() to fire
 * right around the clause's own reported unlock instant — on top of, not
 * instead of, the regular fantasy:process-clause-orders poll (routes/
 * console.php), which stays the safety net if this job is ever lost (queue
 * worker down, restart, deploy, ...). Whichever of the two reaches
 * ClausePurchaseOrderService::execute() first wins the pay attempt — it
 * claims the order atomically so they can never both pay it (see
 * FantasyClausePurchaseOrder::STATUS_EXECUTING).
 */
class ProcessClauseOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(ClausePurchaseOrderService $orders): void
    {
        $order = FantasyClausePurchaseOrder::with(['account', 'league', 'targetTeam'])->find($this->orderId);

        // Already handled (executed/confirmed/cancelled) or gone by the time
        // this fires — the regular poll, a manual confirmation or the user
        // cancelling it may all have beaten this job to it.
        if (! $order || $order->status !== FantasyClausePurchaseOrder::STATUS_PENDING) {
            return;
        }

        $orders->attempt($order);
    }
}
