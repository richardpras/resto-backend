<?php

namespace App\Console\Commands;

use App\Modules\LoyaltyEngine\Services\LoyaltyExpiryService;
use Illuminate\Console\Command;

class ProcessLoyaltyExpiryCommand extends Command
{
    protected $signature = 'loyalty:process-expiry';

    protected $description = 'Expire loyalty points for eligible earning ledger entries';

    public function handle(LoyaltyExpiryService $expiryService): int
    {
        $created = $expiryService->processAllPrograms();
        $this->info("Loyalty expiry entries created: {$created}");

        return self::SUCCESS;
    }
}
