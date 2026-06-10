<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\NotificationSyncService;
use Illuminate\Console\Command;

class SyncStaffNotificationsCommand extends Command
{
    protected $signature = 'notifications:sync-staff {--outletId=}';

    protected $description = 'Sync staff notifications from monitoring and accounting health sources';

    public function handle(NotificationSyncService $notificationSyncService): int
    {
        $outletId = $this->option('outletId');
        if (is_string($outletId) && trim($outletId) !== '') {
            $notificationSyncService->syncOutlet((int) $outletId);
            $this->info('Synced staff notifications for outlet '.$outletId);

            return self::SUCCESS;
        }

        $notificationSyncService->syncAllActiveOutlets();
        $this->info('Synced staff notifications for all active outlets');

        return self::SUCCESS;
    }
}
