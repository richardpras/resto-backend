<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentGatewayService;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Console\Command;

class RecoverStuckPaymentsCommand extends Command
{
    protected $signature = 'payments:recover-stuck {--limit=100 : Maximum stuck transactions to enqueue}';

    protected $description = 'Dispatch recovery jobs for stale pending payment transactions';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $commandContext = AsyncOperationContext::capture([
            'operation' => 'payments.recover_stuck',
            'command' => (string) $this->getName(),
        ]);
        AsyncOperationContext::apply($commandContext);

        $count = $paymentGatewayService->dispatchPendingReconciliation((int) $this->option('limit'), $commandContext);
        $this->info('Dispatched recovery for '.$count.' stale payment transactions.');

        return self::SUCCESS;
    }
}
