<?php

namespace App\Console\Commands;

use App\Modules\LoyaltyEngine\Services\LoyaltyAutomationService;
use Illuminate\Console\Command;

class ProcessLoyaltyAutomationsCommand extends Command
{
    protected $signature = 'loyalty:process-automations';

    protected $description = 'Process scheduled loyalty automations (birthday, inactive member)';

    public function handle(LoyaltyAutomationService $automationService): int
    {
        $processed = $automationService->processScheduledAutomations();
        $this->info("Loyalty automation executions: {$processed}");

        return self::SUCCESS;
    }
}
