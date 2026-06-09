<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationNotification;
use App\Models\User;
use Illuminate\Support\Collection;

final class MenuAutomationService
{
    public function __construct(
        private readonly AlertEvaluationService $alertEvaluation,
        private readonly AlertRuleService $ruleService,
        private readonly AutomationSnapshotService $snapshotService,
        private readonly AutomationSchedulerService $scheduler,
        private readonly EscalationService $escalationService,
        private readonly MenuAutomationAuditService $auditService,
        private readonly MenuIntelligenceCacheService $cacheService,
    ) {}

    /** @return Collection<int, AutomationAlert> */
    public function getAlerts(int $outletId, ?string $status = null): Collection
    {
        $query = AutomationAlert::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('triggered_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /** @return Collection<int, AutomationAlert> */
    public function getOpenAlerts(int $outletId): Collection
    {
        return $this->getAlerts($outletId, AutomationAlert::STATUS_OPEN);
    }

    /** @return Collection<int, AutomationAlert> */
    public function getCriticalAlerts(int $outletId): Collection
    {
        return AutomationAlert::query()
            ->where('outlet_id', $outletId)
            ->where('status', AutomationAlert::STATUS_OPEN)
            ->where('severity', 'critical')
            ->orderByDesc('triggered_at')
            ->get();
    }

    /** @return Collection<int, AutomationAlert> */
    public function getAlertHistory(int $outletId): Collection
    {
        return AutomationAlert::query()
            ->where('outlet_id', $outletId)
            ->where('status', AutomationAlert::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->get();
    }

    /** @return Collection<int, AutomationNotification> */
    public function getNotifications(int $outletId): Collection
    {
        return AutomationNotification::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return array<string,mixed> */
    public function getDashboardSummary(int $outletId): array
    {
        return $this->cacheService->remember(
            $outletId,
            MenuIntelligenceCacheService::PREFIX_AUTOMATION,
            MenuIntelligenceCacheService::TTL_AUTOMATION,
            fn (): array => $this->buildDashboardSummary($outletId),
        );
    }

    /** @return array<string,mixed> */
    private function buildDashboardSummary(int $outletId): array
    {
        $open = $this->getOpenAlerts($outletId);
        $critical = $open->where('severity', 'critical')->count();
        $warnings = $open->where('severity', 'warning')->count();
        $high = $open->where('severity', 'high')->count();

        return [
            'outletId' => $outletId,
            'openAlerts' => $open->count(),
            'criticalAlerts' => $critical,
            'highAlerts' => $high,
            'warningAlerts' => $warnings,
            'resolvedToday' => AutomationAlert::query()
                ->where('outlet_id', $outletId)
                ->where('status', AutomationAlert::STATUS_RESOLVED)
                ->whereDate('resolved_at', now()->toDateString())
                ->count(),
            'activeRules' => $this->ruleService->listRules($outletId)->where('is_active', true)->count(),
        ];
    }

    public function resolveAlert(int $alertId, int $outletId, ?User $actor = null): AutomationAlert
    {
        $alert = AutomationAlert::query()
            ->where('id', $alertId)
            ->where('outlet_id', $outletId)
            ->firstOrFail();

        $alert->update([
            'status' => AutomationAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $this->auditService->log('automation_alert_resolved', (int) $alert->id, $outletId, $actor, [
            'alertType' => $alert->alert_type,
        ]);

        return $alert->fresh();
    }

    /** @return array<string,mixed> */
    public function runAutomation(int $outletId, ?User $actor = null): array
    {
        $alerts = $this->alertEvaluation->evaluateAll($outletId, $actor);
        $snapshot = $this->snapshotService->createSnapshot($outletId, null, $actor);

        return [
            'outletId' => $outletId,
            'alertsGenerated' => $alerts->count(),
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string,mixed> */
    public function runDailySchedule(int $outletId, ?User $actor = null): array
    {
        return $this->scheduler->runDaily($outletId, $actor);
    }
}
