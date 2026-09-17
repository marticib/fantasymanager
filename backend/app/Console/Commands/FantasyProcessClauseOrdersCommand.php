<?php

namespace App\Console\Commands;

use App\Models\FantasyClausePurchaseOrder;
use App\Services\Automation\ClausePurchaseOrderService;
use Illuminate\Console\Command;

class FantasyProcessClauseOrdersCommand extends Command
{
    protected $signature = 'fantasy:process-clause-orders';

    protected $description = 'Check every pending clause-purchase order and execute or flag it once its clause unlocks';

    public function handle(ClausePurchaseOrderService $orders): int
    {
        $pending = FantasyClausePurchaseOrder::where('status', FantasyClausePurchaseOrder::STATUS_PENDING)->count();

        if ($pending === 0) {
            $this->info('No pending clause orders.');

            return self::SUCCESS;
        }

        $this->info("Checking {$pending} pending clause order(s).");
        $orders->processPendingOrders();

        return self::SUCCESS;
    }
}
