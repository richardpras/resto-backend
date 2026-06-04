<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EmployeeShiftAssignmentService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeShiftAssignment>
     */
    public function list(?User $user, ?int $outletId = null, ?int $employeeId = null): Collection
    {
        $query = EmployeeShiftAssignment::query()
            ->with(['employee', 'shift', 'outlet'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        $this->applyOutletScope($query, $user);

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        if ($employeeId !== null && $employeeId > 0) {
            $query->where('employee_id', $employeeId);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $assignmentId): EmployeeShiftAssignment
    {
        $assignment = EmployeeShiftAssignment::query()
            ->with(['employee', 'shift', 'outlet'])
            ->find($assignmentId);

        abort_if($assignment === null, 404, 'Shift assignment not found.');

        $this->assertAssignmentOutletAllowed($user, $assignment);

        return $assignment;
    }

    public function create(?User $user, array $payload): EmployeeShiftAssignment
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) $payload['employeeId']);
        $shift = Shift::query()->findOrFail((int) $payload['shiftId']);

        $outletId = (int) $employee->outlet_id;
        abort_if($outletId < 1, 422, 'Employee must have an outlet before shift assignment.');

        $effectiveFrom = Carbon::parse($payload['effectiveFrom'])->startOfDay();
        $effectiveUntil = isset($payload['effectiveUntil']) && $payload['effectiveUntil'] !== null
            ? Carbon::parse($payload['effectiveUntil'])->startOfDay()
            : null;

        $this->assertDateRangeValid($effectiveFrom, $effectiveUntil);
        $this->assertNoOverlappingActiveAssignment(
            (int) $employee->id,
            $effectiveFrom,
            $effectiveUntil,
            null,
        );

        return EmployeeShiftAssignment::query()->create([
            'outlet_id' => $outletId,
            'employee_id' => (int) $employee->id,
            'shift_id' => (int) $shift->id,
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_until' => $effectiveUntil?->toDateString(),
            'is_active' => (bool) ($payload['isActive'] ?? true),
            'notes' => $payload['notes'] ?? null,
        ])->load(['employee', 'shift', 'outlet']);
    }

    public function update(?User $user, int $assignmentId, array $payload): EmployeeShiftAssignment
    {
        $assignment = $this->findAccessible($user, $assignmentId);

        if (isset($payload['employeeId'])) {
            $this->employeeMaster->findAccessible($user, (int) $payload['employeeId']);
        }

        $employeeId = (int) ($payload['employeeId'] ?? $assignment->employee_id);
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        $outletId = (int) $employee->outlet_id;
        abort_if($outletId < 1, 422, 'Employee must have an outlet before shift assignment.');

        $effectiveFrom = isset($payload['effectiveFrom'])
            ? Carbon::parse($payload['effectiveFrom'])->startOfDay()
            : $assignment->effective_from->copy()->startOfDay();

        $effectiveUntil = array_key_exists('effectiveUntil', $payload)
            ? ($payload['effectiveUntil'] !== null ? Carbon::parse($payload['effectiveUntil'])->startOfDay() : null)
            : ($assignment->effective_until ? $assignment->effective_until->copy()->startOfDay() : null);

        $isActive = array_key_exists('isActive', $payload)
            ? (bool) $payload['isActive']
            : (bool) $assignment->is_active;

        $this->assertDateRangeValid($effectiveFrom, $effectiveUntil);

        if ($isActive) {
            $this->assertNoOverlappingActiveAssignment(
                $employeeId,
                $effectiveFrom,
                $effectiveUntil,
                $assignment->id,
            );
        }

        $assignment->fill([
            'outlet_id' => $outletId,
            'employee_id' => $employeeId,
            'shift_id' => (int) ($payload['shiftId'] ?? $assignment->shift_id),
            'effective_from' => $effectiveFrom->toDateString(),
            'effective_until' => $effectiveUntil?->toDateString(),
            'is_active' => $isActive,
            'notes' => array_key_exists('notes', $payload) ? $payload['notes'] : $assignment->notes,
        ])->save();

        return $assignment->refresh()->load(['employee', 'shift', 'outlet']);
    }

    public function deactivate(?User $user, int $assignmentId): EmployeeShiftAssignment
    {
        $assignment = $this->findAccessible($user, $assignmentId);

        $assignment->fill([
            'is_active' => false,
            'effective_until' => $assignment->effective_until ?? now()->toDateString(),
        ])->save();

        return $assignment->refresh()->load(['employee', 'shift', 'outlet']);
    }

    /**
     * @return array{current: ?EmployeeShiftAssignment, history: Collection<int, EmployeeShiftAssignment>}
     */
    public function shiftHistoryForEmployee(?User $user, int $employeeId): array
    {
        $this->employeeMaster->findAccessible($user, $employeeId);

        $history = EmployeeShiftAssignment::query()
            ->with(['shift', 'outlet'])
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $current = $history->first(
            fn (EmployeeShiftAssignment $row) => $row->is_active
                && $this->isCurrentOnDate($row, now()->startOfDay()),
        );

        return [
            'current' => $current,
            'history' => $history,
        ];
    }

    public function currentAssignmentForEmployee(?User $user, int $employeeId, ?Carbon $onDate = null): ?EmployeeShiftAssignment
    {
        $this->employeeMaster->findAccessible($user, $employeeId);

        $date = ($onDate ?? now())->copy()->startOfDay();

        return EmployeeShiftAssignment::query()
            ->with(['shift', 'outlet'])
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->get()
            ->first(fn (EmployeeShiftAssignment $row) => $this->isCurrentOnDate($row, $date));
    }

    private function isCurrentOnDate(EmployeeShiftAssignment $row, Carbon $date): bool
    {
        $from = $row->effective_from->copy()->startOfDay();
        $until = $row->effective_until
            ? $row->effective_until->copy()->startOfDay()
            : null;

        if ($date->lt($from)) {
            return false;
        }

        if ($until !== null && $date->gt($until)) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<EmployeeShiftAssignment>  $query
     */
    private function applyOutletScope(Builder $query, ?User $user): void
    {
        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');
    }

    private function assertAssignmentOutletAllowed(?User $user, EmployeeShiftAssignment $assignment): void
    {
        if ($user === null) {
            return;
        }

        $assignment->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $assignment->employee);
    }

    private function assertDateRangeValid(Carbon $from, ?Carbon $until): void
    {
        if ($until !== null && $until->lt($from)) {
            throw ValidationException::withMessages([
                'effectiveUntil' => ['Effective until must be on or after effective from.'],
            ]);
        }
    }

    private function assertNoOverlappingActiveAssignment(
        int $employeeId,
        Carbon $from,
        ?Carbon $until,
        ?int $ignoreId,
    ): void {
        $candidates = EmployeeShiftAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->get();

        foreach ($candidates as $existing) {
            $existingFrom = $existing->effective_from->copy()->startOfDay();
            $existingUntil = $existing->effective_until
                ? $existing->effective_until->copy()->startOfDay()
                : null;

            if ($this->rangesOverlap($from, $until, $existingFrom, $existingUntil)) {
                throw ValidationException::withMessages([
                    'effectiveFrom' => ['This employee already has an overlapping active shift assignment for the selected date range.'],
                ]);
            }
        }
    }

    private function rangesOverlap(Carbon $from1, ?Carbon $until1, Carbon $from2, ?Carbon $until2): bool
    {
        $end1 = $until1 ?? Carbon::parse('2099-12-31');
        $end2 = $until2 ?? Carbon::parse('2099-12-31');

        return $from1->lte($end2) && $from2->lte($end1);
    }
}
