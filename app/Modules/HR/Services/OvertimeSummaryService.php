<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\OvertimeRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class OvertimeSummaryService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, OvertimeDailySummary>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = OvertimeDailySummary::query()
            ->with('employee')
            ->orderByDesc('overtime_date')
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['fromDate'])) {
            $query->where('overtime_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('overtime_date', '<=', $filters['toDate']);
        }

        if (! empty($filters['outletId'])) {
            $query->whereHas('employee', fn ($q) => $q->where('outlet_id', (int) $filters['outletId']));
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): OvertimeDailySummary
    {
        $summary = OvertimeDailySummary::query()
            ->with('employee')
            ->find($id);

        abort_if($summary === null, Response::HTTP_NOT_FOUND, 'Overtime summary not found.');

        $summary->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $summary->employee);

        return $summary;
    }

    public function rebuildForEmployeeDate(int $employeeId, string $date): ?OvertimeDailySummary
    {
        $approved = OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->where('overtime_date', $date)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->get();

        if ($approved->isEmpty()) {
            OvertimeDailySummary::query()
                ->where('employee_id', $employeeId)
                ->where('overtime_date', $date)
                ->delete();

            return null;
        }

        $minutes = (int) $approved->sum('total_minutes');
        $hours = round($minutes / 60, 2);

        return OvertimeDailySummary::query()->updateOrCreate(
            [
                'employee_id' => $employeeId,
                'overtime_date' => $date,
            ],
            [
                'approved_minutes' => $minutes,
                'approved_hours' => $hours,
                'request_count' => $approved->count(),
            ],
        );
    }

    /**
     * @return array{minutes: int, hours: float}
     */
    public function periodTotalsForEmployee(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $rows = OvertimeDailySummary::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('overtime_date', [$periodStart, $periodEnd])
            ->get();

        $minutes = (int) $rows->sum('approved_minutes');

        return [
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 2),
        ];
    }
}
