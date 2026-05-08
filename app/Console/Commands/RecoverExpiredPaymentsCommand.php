<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentGatewayService;
use Illuminate\Console\Command;

class RecoverExpiredPaymentsCommand extends Command
{
    protected $signature = 'payments:recover-expired {--limit=100 : Maximum pending transactions to expire}';

    protected $description = 'Expire pending payments safely for recovery flows';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $expired = $paymentGatewayService->expirePendingTransactions((int) $this->option('limit'));
        $this->info('Recovered expired state for '.$expired->count().' pending transactions.');

        return self::SUCCESS;
    }
}
