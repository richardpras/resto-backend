<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceSummaryQueryService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, AttendanceDailySummary>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = AttendanceDailySummary::query()
            ->with(['employee', 'shift', 'attendanceRecord'])
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === 'exception') {
                $query->where(function (Builder $q) {
                    $q->where('is_absent', true)
                        ->orWhere('is_incomplete', true)
                        ->orWhere('requires_review', true)
                        ->orWhere('attendance_status', AttendanceDailySummary::STATUS_REVIEW_REQUIRED);
                });
            } else {
                $query->where('attendance_status', $status);
            }
        }

        if (! empty($filters['fromDate'])) {
            $query->where('attendance_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('attendance_date', '<=', $filters['toDate']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): AttendanceDailySummary
    {
        $summary = AttendanceDailySummary::query()
            ->with(['employee', 'shift', 'attendanceRecord', 'reviews.reviewer'])
            ->find($id);

        abort_if($summary === null, Response::HTTP_NOT_FOUND, 'Attendance summary not found.');

        if ($user !== null) {
            $summary->loadMissing('employee');
            try {
                $this->employeeMaster->assertEmployeeOutletAllowed($user, $summary->employee);
            } catch (ValidationException) {
                abort(Response::HTTP_FORBIDDEN, 'You cannot access attendance summaries for this outlet.');
            }
        }

        return $summary;
    }
}
