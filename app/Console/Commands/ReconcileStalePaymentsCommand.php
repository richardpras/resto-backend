<?php

namespace App\Console\Commands;

use App\Jobs\Payments\RecoverStalePaymentsJob;
use App\Modules\Payments\Services\PaymentGatewayService;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Console\Command;

class ReconcileStalePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-stale {--limit=100 : Maximum pending transactions to reconcile} {--sync : Process immediately without queue}';

    protected $description = 'Reconcile stale pending payment transactions';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $commandContext = AsyncOperationContext::capture([
            'operation' => 'payments.reconcile_stale',
            'command' => (string) $this->getName(),
        ]);
        AsyncOperationContext::apply($commandContext);

        $limit = (int) $this->option('limit');
        if ((bool) $this->option('sync')) {
            $updated = $paymentGatewayService->reconcilePendingTransactions([], $limit, $commandContext);
            $this->info('Reconciled '.$updated->count().' payment transactions (sync).');

            return self::SUCCESS;
        }

        RecoverStalePaymentsJob::dispatch($limit, $commandContext);
        $this->info('Dispatched stale payment reconciliation recovery job.');

        return self::SUCCESS;
    }
}
