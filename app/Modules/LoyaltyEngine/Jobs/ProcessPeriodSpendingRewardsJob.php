<?php

namespace App\Modules\LoyaltyEngine\Jobs;

use App\Modules\LoyaltyEngine\Services\LoyaltyPeriodSpendingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPeriodSpendingRewardsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(LoyaltyPeriodSpendingService $periodSpendingService): void
    {
        $periodSpendingService->processAllActivePrograms();
    }
}
