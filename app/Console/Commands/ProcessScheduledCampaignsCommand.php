<?php

namespace App\Console\Commands;

use App\Modules\LoyaltyEngine\Services\LoyaltyCampaignExecutionService;
use Illuminate\Console\Command;

class ProcessScheduledCampaignsCommand extends Command
{
    protected $signature = 'loyalty:process-campaigns';

    protected $description = 'Activate scheduled loyalty campaigns whose scheduled time has passed';

    public function handle(LoyaltyCampaignExecutionService $executionService): int
    {
        $processed = $executionService->processDueScheduledCampaigns();
        $this->info("Loyalty campaigns activated: {$processed}");

        return self::SUCCESS;
    }
}
