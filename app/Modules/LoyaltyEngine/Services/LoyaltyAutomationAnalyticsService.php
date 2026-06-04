<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomationLog;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyAutomationAnalyticsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array{
     *     automationsCount: int,
     *     activeAutomations: int,
     *     automationExecutions: int,
     *     automationSuccess: int,
     *     automationFailed: int,
     *     automationSummary: list<array{automation: string, executions: int}>
     * }
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $automationsCount = (int) LoyaltyAutomation::query()->where('outlet_id', $outletId)->count();
        $activeAutomations = (int) LoyaltyAutomation::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->count();

        $logBase = LoyaltyAutomationLog::query()
            ->whereIn('automation_id', function ($query) use ($outletId): void {
                $query->select('id')
                    ->from('loyalty_automations')
                    ->where('outlet_id', $outletId);
            });

        $automationExecutions = (int) (clone $logBase)->count();
        $automationSuccess = (int) (clone $logBase)
            ->where('status', LoyaltyAutomationLog::STATUS_SUCCESS)
            ->count();
        $automationFailed = (int) (clone $logBase)
            ->where('status', LoyaltyAutomationLog::STATUS_FAILED)
            ->count();

        $summaryRows = DB::table('loyalty_automation_logs as logs')
            ->join('loyalty_automations as automations', 'automations.id', '=', 'logs.automation_id')
            ->select('automations.name as automation_name', DB::raw('COUNT(*) as aggregate'))
            ->where('automations.outlet_id', $outletId)
            ->where('logs.status', LoyaltyAutomationLog::STATUS_SUCCESS)
            ->groupBy('automations.id', 'automations.name')
            ->orderBy('automations.name')
            ->get();

        $automationSummary = $summaryRows->map(fn ($row) => [
            'automation' => (string) $row->automation_name,
            'executions' => (int) $row->aggregate,
        ])->values()->all();

        return [
            'automationsCount' => $automationsCount,
            'activeAutomations' => $activeAutomations,
            'automationExecutions' => $automationExecutions,
            'automationSuccess' => $automationSuccess,
            'automationFailed' => $automationFailed,
            'automationSummary' => $automationSummary,
        ];
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}
