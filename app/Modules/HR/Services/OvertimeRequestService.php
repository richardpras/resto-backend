<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\OvertimeRequest;
use App\Models\User;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OvertimeRequestService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly OvertimeTypeService $overtimeTypes,
        private readonly OvertimeSummaryService $summaries,
        private readonly ApprovalNotificationService $approvalNotificationService,
    ) {}

    /**
     * @return Collection<int, OvertimeRequest>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = OvertimeRequest::query()
            ->with(['employee', 'overtimeType'])
            ->orderByDesc('overtime_date')
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

        if (! empty($filters['overtimeTypeId'])) {
            $query->where('overtime_type_id', (int) $filters['overtimeTypeId']);
        }

        if (! empty($filters['fromDate'])) {
            $query->where('overtime_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('overtime_date', '<=', $filters['toDate']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $id): OvertimeRequest
    {
        $request = OvertimeRequest::query()
            ->with(['employee', 'overtimeType'])
            ->find($id);

        abort_if($request === null, Response::HTTP_NOT_FOUND, 'Overtime request not found.');

        $request->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $request->employee);

        return $request;
    }

    public function create(?User $user, array $payload): OvertimeRequest
    {
        $employeeId = (int) ($payload['employeeId'] ?? 0);
        $typeId = (int) ($payload['overtimeTypeId'] ?? 0);
        $date = (string) ($payload['overtimeDate'] ?? '');
        $startTime = (string) ($payload['startTime'] ?? '');
        $endTime = (string) ($payload['endTime'] ?? '');

        $employee = $this->employeeMaster->findAccessible($user, $employeeId);
        $type = $this->overtimeTypes->findAccessible($user, $typeId);

        if ((int) $type->outlet_id !== (int) $employee->outlet_id) {
            throw ValidationException::withMessages([
                'overtimeTypeId' => ['Overtime type must belong to the employee outlet.'],
            ]);
        }

        if (! $type->is_active) {
            throw ValidationException::withMessages([
                'overtimeTypeId' => ['Overtime type is not active.'],
            ]);
        }

        $duration = $this->calculateDuration($startTime, $endTime);
        $this->assertNoOverlappingApproved($employeeId, $date, $startTime, $endTime);

        $request = OvertimeRequest::query()->create([
            'outlet_id' => (int) $employee->outlet_id,
            'employee_id' => $employee->id,
            'overtime_type_id' => $type->id,
            'overtime_date' => $date,
            'start_time' => $duration['start'],
            'end_time' => $duration['end'],
            'total_minutes' => $duration['minutes'],
            'total_hours' => $duration['hours'],
            'reason' => $payload['reason'] ?? null,
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);

        if ($user !== null) {
            $this->approvalNotificationService->overtimeRequestSubmitted($request->load(['employee', 'overtimeType']), $user);
        }

        return $request;
    }

    public function approve(?User $user, int $id): OvertimeRequest
    {
        $request = $this->findAccessible($user, $id);

        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be approved.'],
            ]);
        }

        $this->assertNoOverlappingApproved(
            (int) $request->employee_id,
            $request->overtime_date->toDateString(),
            substr((string) $request->start_time, 0, 8),
            substr((string) $request->end_time, 0, 8),
            (int) $request->id,
        );

        $request->update([
            'status' => OvertimeRequest::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        $this->summaries->rebuildForEmployeeDate(
            (int) $request->employee_id,
            $request->overtime_date->toDateString(),
        );

        $fresh = $request->refresh()->load(['employee', 'overtimeType']);
        if ($user !== null) {
            $this->approvalNotificationService->overtimeRequestApproved($fresh, $user);
        }

        return $fresh;
    }

    public function reject(?User $user, int $id, array $payload): OvertimeRequest
    {
        $request = $this->findAccessible($user, $id);

        if ($request->status !== OvertimeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be rejected.'],
            ]);
        }

        $request->update([
            'status' => OvertimeRequest::STATUS_REJECTED,
            'rejected_by' => $user?->id,
            'rejected_at' => now(),
            'rejection_reason' => $payload['rejectionReason'] ?? null,
        ]);

        $fresh = $request->refresh()->load(['employee', 'overtimeType']);
        if ($user !== null) {
            $this->approvalNotificationService->overtimeRequestRejected($fresh, $user);
        }

        return $fresh;
    }

    public function cancel(?User $user, int $id): OvertimeRequest
    {
        $request = $this->findAccessible($user, $id);

        if (! in_array($request->status, [OvertimeRequest::STATUS_PENDING, OvertimeRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only pending or approved requests can be cancelled.'],
            ]);
        }

        $wasApproved = $request->status === OvertimeRequest::STATUS_APPROVED;
        $employeeId = (int) $request->employee_id;
        $date = $request->overtime_date->toDateString();

        $request->update([
            'status' => OvertimeRequest::STATUS_CANCELLED,
        ]);

        if ($wasApproved) {
            $this->summaries->rebuildForEmployeeDate($employeeId, $date);
        }

        return $request->refresh()->load(['employee', 'overtimeType']);
    }

    /**
     * @return array{start: string, end: string, minutes: int, hours: float}
     */
    public function calculateDuration(string $startTime, string $endTime): array
    {
        $start = $this->normalizeTime($startTime);
        $end = $this->normalizeTime($endTime);

        $startCarbon = Carbon::parse('2000-01-01 '.$start);
        $endCarbon = Carbon::parse('2000-01-01 '.$end);

        if ($endCarbon->lte($startCarbon)) {
            throw ValidationException::withMessages([
                'endTime' => ['End time must be after start time.'],
            ]);
        }

        $minutes = (int) $startCarbon->diffInMinutes($endCarbon);

        return [
            'start' => $start,
            'end' => $end,
            'minutes' => $minutes,
            'hours' => round($minutes / 60, 2),
        ];
    }

    private function normalizeTime(string $value): string
    {
        $str = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $str) === 1) {
            return $str.':00';
        }

        return strlen($str) >= 8 ? substr($str, 0, 8) : $str;
    }

    private function assertNoOverlappingApproved(
        int $employeeId,
        string $date,
        string $startTime,
        string $endTime,
        ?int $excludeId = null,
    ): void {
        $newStart = Carbon::parse($date.' '.$this->normalizeTime($startTime));
        $newEnd = Carbon::parse($date.' '.$this->normalizeTime($endTime));

        $query = OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->where('overtime_date', $date)
            ->where('status', OvertimeRequest::STATUS_APPROVED);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        foreach ($query->get() as $existing) {
            $existStart = Carbon::parse($date.' '.substr((string) $existing->start_time, 0, 8));
            $existEnd = Carbon::parse($date.' '.substr((string) $existing->end_time, 0, 8));

            if ($newStart->lt($existEnd) && $existStart->lt($newEnd)) {
                throw ValidationException::withMessages([
                    'startTime' => ['Employee already has approved overtime overlapping this time range.'],
                ]);
            }
        }
    }
}
