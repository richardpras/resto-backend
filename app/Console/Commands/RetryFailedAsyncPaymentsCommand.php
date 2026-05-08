<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentGatewayService;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Console\Command;

class RetryFailedAsyncPaymentsCommand extends Command
{
    protected $signature = 'payments:retry-async-failures {--limit=50 : Maximum failed async transactions to retry}';

    protected $description = 'Retry pending payments that previously failed async reconciliation';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $commandContext = AsyncOperationContext::capture([
            'operation' => 'payments.retry_async_failures',
            'command' => (string) $this->getName(),
        ]);
        AsyncOperationContext::apply($commandContext);

        $count = $paymentGatewayService->retryFailedAsyncPostings((int) $this->option('limit'), $commandContext);
        $this->info('Dispatched '.$count.' async payment retry jobs.');

        return self::SUCCESS;
    }
}
