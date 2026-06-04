<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\LeaveRequest;
use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LeaveRequestService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly LeaveTypeService $leaveTypes,
        private readonly LeaveBalanceService $leaveBalances,
    ) {}

    /**
     * @return Collection<int, LeaveRequest>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['leaveTypeId'])) {
            $query->where('leave_type_id', (int) $filters['leaveTypeId']);
        }

        if (! empty($filters['fromDate'])) {
            $query->where('end_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('start_date', '<=', $filters['toDate']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): LeaveRequest
    {
        $request = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->find($id);

        abort_if($request === null, Response::HTTP_NOT_FOUND, 'Leave request not found.');

        $request->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $request->employee);

        return $request;
    }

    public function create(?User $user, array $payload): LeaveRequest
    {
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $leaveTypeId = (int) ($payload['leaveTypeId'] ?? 0);
        $startDate = (string) ($payload['startDate'] ?? '');
        $endDate = (string) ($payload['endDate'] ?? '');

        $employee = $this->employeeMaster->findAccessible($user, $employeeId);
        $type = $this->leaveTypes->findAccessible($user, $leaveTypeId);

        if ((int) $type->outlet_id !== (int) $employee->outlet_id) {
            throw ValidationException::withMessages([
                'leaveTypeId' => ['Leave type must belong to the employee outlet.'],
            ]);
        }

        if (! $type->is_active) {
            throw ValidationException::withMessages([
                'leaveTypeId' => ['Leave type is not active.'],
            ]);
        }

        if ($endDate < $startDate) {
            throw ValidationException::withMessages([
                'endDate' => ['endDate must be on or after startDate.'],
            ]);
        }

        if ($type->requires_attachment && empty($payload['attachmentPath'])) {
            throw ValidationException::withMessages([
                'attachmentPath' => ['Attachment is required for this leave type.'],
            ]);
        }

        $totalDays = $this->calculateTotalDays($startDate, $endDate);

        $this->assertNoOverlappingApprovedLeave($employeeId, $startDate, $endDate);

        return LeaveRequest::query()->create([
            'outlet_id' => (int) $employee->outlet_id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'reason' => $payload['reason'] ?? null,
            'attachment_path' => $payload['attachmentPath'] ?? null,
            'status' => LeaveRequest::STATUS_PENDING,
        ]);
    }

    public function approve(?User $user, int $id): LeaveRequest
    {
        $request = $this->findAccessible($user, $id);

        if ($request->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be approved.'],
            ]);
        }

        $this->assertNoOverlappingApprovedLeave(
            (int) $request->employee_id,
            $request->start_date->toDateString(),
            $request->end_date->toDateString(),
            (int) $request->id,
        );

        $request->load('leaveType');
        $this->leaveBalances->consumeForApproval(
            (int) $request->employee_id,
            (int) $request->leave_type_id,
            (float) $request->total_days,
        );

        $request->update([
            'status' => LeaveRequest::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $request->refresh()->load(['employee', 'leaveType']);
    }

    public function reject(?User $user, int $id, array $payload): LeaveRequest
    {
        $request = $this->findAccessible($user, $id);

        if ($request->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be rejected.'],
            ]);
        }

        $request->update([
            'status' => LeaveRequest::STATUS_REJECTED,
            'rejected_by' => $user?->id,
            'rejected_at' => now(),
            'rejection_reason' => $payload['rejectionReason'] ?? null,
        ]);

        return $request->refresh()->load(['employee', 'leaveType']);
    }

    public function cancel(?User $user, int $id): LeaveRequest
    {
        $request = $this->findAccessible($user, $id);

        if (! in_array($request->status, [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved requests can be cancelled.'],
            ]);
        }

        if ($request->status === LeaveRequest::STATUS_APPROVED) {
            $this->leaveBalances->restoreForCancellation(
                (int) $request->employee_id,
                (int) $request->leave_type_id,
                (float) $request->total_days,
            );
        }

        $request->update([
            'status' => LeaveRequest::STATUS_CANCELLED,
        ]);

        return $request->refresh()->load(['employee', 'leaveType']);
    }

    public function calculateTotalDays(string $startDate, string $endDate): int
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }

    private function assertNoOverlappingApprovedLeave(
        int $employeeId,
        string $startDate,
        string $endDate,
        ?int $excludeRequestId = null,
    ): void {
        $query = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeRequestId !== null) {
            $query->where('id', '!=', $excludeRequestId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'startDate' => ['Employee already has approved leave overlapping this date range.'],
            ]);
        }
    }
}
