<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentIncidentService;
use Illuminate\Console\Command;

final class PaymentIncidentCheckCommand extends Command
{
    protected $signature = 'payment:incident-check';

    protected $description = 'Detect payment degradation, outages, and webhook/stale spikes';

    public function handle(PaymentIncidentService $incidentService): int
    {
        $opened = $incidentService->checkAllOutlets();
        $this->info("Payment incident check complete. New incidents opened: {$opened}");

        return self::SUCCESS;
    }
}
