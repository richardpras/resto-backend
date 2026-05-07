<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentGatewayService;
use Illuminate\Console\Command;

class ReconcileStalePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-stale {--limit=100 : Maximum pending transactions to reconcile}';

    protected $description = 'Reconcile stale pending payment transactions';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $limit = (int) $this->option('limit');
        $updated = $paymentGatewayService->reconcilePendingTransactions([], $limit);

        $this->info('Reconciled '.$updated->count().' payment transactions.');

        return self::SUCCESS;
    }
}
