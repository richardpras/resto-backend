<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentGatewayService;
use Illuminate\Console\Command;

class ExpirePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:expire-pending {--limit=100 : Maximum pending transactions to expire}';

    protected $description = 'Expire pending payment transactions past expiry time';

    public function handle(PaymentGatewayService $paymentGatewayService): int
    {
        $limit = (int) $this->option('limit');
        $expired = $paymentGatewayService->expirePendingTransactions($limit);

        $this->info('Expired '.$expired->count().' payment transactions.');

        return self::SUCCESS;
    }
}
