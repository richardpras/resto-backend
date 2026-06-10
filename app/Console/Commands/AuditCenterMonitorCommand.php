<?php

namespace App\Console\Commands;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\Adapters\CriticalAuditNotificationAdapter;
use App\Modules\System\Services\AuditCenterService;
use Illuminate\Console\Command;

final class AuditCenterMonitorCommand extends Command
{
    protected $signature = 'audit-center:monitor {--minutes=60 : Lookback window in minutes}';

    protected $description = 'Scan recent critical audit events and dispatch Notification Center alerts';

    public function handle(
        AuditCenterService $auditCenterService,
        CriticalAuditNotificationAdapter $notificationAdapter,
    ): int {
        $minutes = max(1, (int) $this->option('minutes'));
        $start = now()->subMinutes($minutes);

        $notified = 0;
        $outletIds = Outlet::query()->pluck('id')->map(static fn ($id): int => (int) $id);

        foreach ($outletIds as $outletId) {
            $summary = $auditCenterService->dashboardSummary($outletId);

            foreach ($summary['riskEvents'] as $record) {
                if ($record->timestamp < $start->toIso8601String()) {
                    continue;
                }

                $notificationAdapter->notifyCriticalEvent($outletId, $record);
                $notified++;
            }
        }

        $this->info(sprintf('Audit center monitor: notified=%d window=%dm', $notified, $minutes));

        return self::SUCCESS;
    }
}
