<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendanceRecordQueryService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, AttendanceRecord>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = AttendanceRecord::query()
            ->with(['employee', 'shift', 'roster'])
            ->orderByDesc('attendance_date')
            ->orderByDesc('id');

        $this->applyOutletScope($query, $user);

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['departmentId'])) {
            $departmentId = (int) $filters['departmentId'];
            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $departmentId));
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['fromDate'])) {
            $query->where('attendance_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('attendance_date', '<=', $filters['toDate']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with(['employee', 'shift', 'roster'])
            ->find($id);

        abort_if($record === null, Response::HTTP_NOT_FOUND, 'Attendance record not found.');

        if ($user !== null) {
            $record->loadMissing('employee');
            try {
                $this->employeeMaster->assertEmployeeOutletAllowed($user, $record->employee);
            } catch (ValidationException) {
                abort(Response::HTTP_FORBIDDEN, 'You cannot access attendance for this outlet.');
            }
        }

        return $record;
    }

    /**
     * @return Collection<int, AttendanceRecord>
     */
    public function employeeHistory(?User $user, int $employeeId, int $limit = 30): Collection
    {
        $this->employeeMaster->findAccessible($user, $employeeId);

        return AttendanceRecord::query()
            ->with(['shift', 'roster'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('attendance_date')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     */
    private function applyOutletScope(Builder $query, ?User $user): void
    {
        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');
    }
}
