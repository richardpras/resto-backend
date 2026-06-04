<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeLeaveBalance;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeLeaveBalance>
     */
    public function listForEmployee(?User $user, int $employeeId): Collection
    {
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        return EmployeeLeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderBy('leave_type_id')
            ->get();
    }

    /**
     * @param  list<array{leaveTypeId: int, allocatedDays: float|int|string}>  $rows
     * @return Collection<int, EmployeeLeaveBalance>
     */
    public function updateAllocations(?User $user, int $employeeId, array $rows): Collection
    {
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        foreach ($rows as $row) {
            $leaveTypeId = (int) ($row['leaveTypeId'] ?? 0);
            $allocated = (float) ($row['allocatedDays'] ?? 0);

            $type = LeaveType::query()->find($leaveTypeId);
            if ($type === null || (int) $type->outlet_id !== (int) $employee->outlet_id) {
                throw ValidationException::withMessages([
                    'leaveTypeId' => ['Invalid leave type for this employee outlet.'],
                ]);
            }

            $balance = EmployeeLeaveBalance::query()->firstOrCreate(
                [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveTypeId,
                ],
                [
                    'allocated_days' => 0,
                    'used_days' => 0,
                    'remaining_days' => 0,
                ],
            );

            $used = (float) $balance->used_days;
            if ($allocated < $used) {
                throw ValidationException::withMessages([
                    'allocatedDays' => ["Allocated days cannot be less than used days ({$used}) for {$type->name}."],
                ]);
            }

            $balance->update([
                'allocated_days' => $allocated,
                'remaining_days' => $allocated - $used,
            ]);
        }

        return $this->listForEmployee($user, $employeeId);
    }

    public function consumeForApproval(int $employeeId, int $leaveTypeId, float $days): void
    {
        $type = LeaveType::query()->find($leaveTypeId);
        if ($type === null || ! $type->deduct_leave_balance) {
            return;
        }

        $balance = EmployeeLeaveBalance::query()->firstOrCreate(
            [
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
            ],
            [
                'allocated_days' => 0,
                'used_days' => 0,
                'remaining_days' => 0,
            ],
        );

        if ((float) $balance->remaining_days < $days) {
            throw ValidationException::withMessages([
                'totalDays' => ['Insufficient leave balance for this leave type.'],
            ]);
        }

        $used = (float) $balance->used_days + $days;
        $allocated = (float) $balance->allocated_days;

        $balance->update([
            'used_days' => $used,
            'remaining_days' => $allocated - $used,
        ]);
    }

    public function restoreForCancellation(int $employeeId, int $leaveTypeId, float $days): void
    {
        $type = LeaveType::query()->find($leaveTypeId);
        if ($type === null || ! $type->deduct_leave_balance) {
            return;
        }

        $balance = EmployeeLeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        if ($balance === null) {
            return;
        }

        $used = max(0, (float) $balance->used_days - $days);
        $allocated = (float) $balance->allocated_days;

        $balance->update([
            'used_days' => $used,
            'remaining_days' => $allocated - $used,
        ]);
    }
}
