<?php

namespace App\Console\Commands;

use App\Modules\LoyaltyEngine\Services\LoyaltyPeriodSpendingService;
use Illuminate\Console\Command;

class ProcessLoyaltyPeriodRewardsCommand extends Command
{
    protected $signature = 'loyalty:process-period-rewards';

    protected $description = 'Process period_spending loyalty rewards for eligible members';

    public function handle(LoyaltyPeriodSpendingService $periodSpendingService): int
    {
        $created = $periodSpendingService->processAllActivePrograms();
        $this->info("Period spending rewards created: {$created}");

        return self::SUCCESS;
    }
}
