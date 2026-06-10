<?php

namespace App\Modules\Notifications\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Monitoring\Services\MonitoringMetricsService;
use App\Modules\Notifications\Services\Adapters\AccountingNotificationAdapter;
use App\Modules\Notifications\Services\Adapters\MonitoringNotificationAdapter;
use App\Modules\Notifications\Services\Adapters\PaymentNotificationAdapter;

final class NotificationSyncService
{
    public function __construct(
        private readonly MonitoringMetricsService $monitoringMetricsService,
        private readonly AccountingNotificationAdapter $accountingNotificationAdapter,
        private readonly PaymentNotificationAdapter $paymentNotificationAdapter,
        private readonly MonitoringNotificationAdapter $monitoringNotificationAdapter,
    ) {}

    public function syncOutlet(int $outletId): void
    {
        if ($outletId < 1) {
            return;
        }

        $metrics = $this->monitoringMetricsService->aggregateForOutletIds([$outletId]);
        $this->accountingNotificationAdapter->syncHealthAlerts($outletId);
        $this->paymentNotificationAdapter->syncFromMonitoring($outletId, $metrics);
        $this->monitoringNotificationAdapter->syncFromMetrics($outletId, $metrics);
    }

    public function syncAllActiveOutlets(): void
    {
        Outlet::query()
            ->where('status', 'active')
            ->pluck('id')
            ->each(fn ($id) => $this->syncOutlet((int) $id));
    }
}
