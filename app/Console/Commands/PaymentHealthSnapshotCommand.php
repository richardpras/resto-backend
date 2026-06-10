<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentHealthSnapshotService;
use Illuminate\Console\Command;

final class PaymentHealthSnapshotCommand extends Command
{
    protected $signature = 'payment:health-snapshot {--outletId=} {--provider=}';

    protected $description = 'Capture daily payment health snapshots per outlet and provider';

    public function handle(PaymentHealthSnapshotService $snapshotService): int
    {
        $outletId = $this->option('outletId');
        $provider = $this->option('provider');

        if (is_numeric($outletId) && (int) $outletId > 0 && is_string($provider) && trim($provider) !== '') {
            $snapshotService->captureForOutletProvider((int) $outletId, strtolower(trim($provider)));
            $this->info('Payment health snapshot captured for outlet '.$outletId.' / '.$provider);

            return self::SUCCESS;
        }

        $count = $snapshotService->captureAllOutlets()->count();
        $this->info("Payment health snapshots captured: {$count}");

        return self::SUCCESS;
    }
}
