<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationEscalationRule;
use App\Models\User;
use Illuminate\Support\Collection;

final class EscalationService
{
    public function __construct(
        private readonly NotificationDispatchService $notificationService,
        private readonly MenuAutomationAuditService $auditService,
    ) {}

    public function ensureDefaultEscalationRules(?int $outletId = null): void
    {
        $defaults = [
            ['day_offset' => 0, 'notify_role' => 'manager'],
            ['day_offset' => 1, 'notify_role' => 'manager'],
            ['day_offset' => 3, 'notify_role' => 'operations_lead'],
            ['day_offset' => 7, 'notify_role' => 'executive'],
        ];

        foreach ($defaults as $row) {
            AutomationEscalationRule::query()->firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'severity' => 'critical',
                    'day_offset' => $row['day_offset'],
                    'notify_role' => $row['notify_role'],
                ],
                ['is_active' => true],
            );
        }
    }

    /** @return Collection<int, AutomationEscalationRule> */
    public function listEscalationRules(?int $outletId = null): Collection
    {
        $this->ensureDefaultEscalationRules($outletId);

        return AutomationEscalationRule::query()
            ->where(function ($query) use ($outletId): void {
                $query->whereNull('outlet_id');
                if ($outletId !== null) {
                    $query->orWhere('outlet_id', $outletId);
                }
            })
            ->where('is_active', true)
            ->orderBy('day_offset')
            ->get();
    }

    /** @return array<int,array<string,mixed>> */
    public function runEscalations(int $outletId, ?User $actor = null): array
    {
        $this->ensureDefaultEscalationRules($outletId);
        $rules = $this->listEscalationRules($outletId);
        $results = [];

        $openCritical = AutomationAlert::query()
            ->where('outlet_id', $outletId)
            ->where('status', AutomationAlert::STATUS_OPEN)
            ->where('severity', 'critical')
            ->get();

        foreach ($openCritical as $alert) {
            $daysOpen = (int) $alert->triggered_at->diffInDays(now());

            foreach ($rules as $rule) {
                if ((int) $rule->day_offset !== $daysOpen) {
                    continue;
                }

                $this->notificationService->dispatch($alert, ['database', 'email'], $actor);

                $this->auditService->log('automation_escalation_triggered', (int) $alert->id, $outletId, $actor, [
                    'dayOffset' => $rule->day_offset,
                    'notifyRole' => $rule->notify_role,
                ]);

                $results[] = [
                    'alertId' => (string) $alert->id,
                    'dayOffset' => $rule->day_offset,
                    'notifyRole' => $rule->notify_role,
                ];
            }
        }

        return $results;
    }
}
