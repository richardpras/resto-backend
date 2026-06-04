<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollAdjustment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PayrollAdjustmentService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, PayrollAdjustment>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = PayrollAdjustment::query()
            ->with('employee')
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

        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }

        if (! empty($filters['periodFrom'])) {
            $query->where('effective_to', '>=', (string) $filters['periodFrom']);
        }

        if (! empty($filters['periodTo'])) {
            $query->where('effective_from', '<=', (string) $filters['periodTo']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $adjustmentId): PayrollAdjustment
    {
        $row = PayrollAdjustment::query()->with('employee')->find($adjustmentId);

        abort_if($row === null, Response::HTTP_NOT_FOUND, 'Payroll adjustment not found.');

        $row->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $row->employee);

        return $row;
    }

    public function create(?User $user, array $payload): PayrollAdjustment
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) ($payload['employeeId'] ?? 0));

        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $type = (string) ($payload['type'] ?? '');
        $category = (string) ($payload['category'] ?? '');
        $effectiveFrom = (string) ($payload['effectiveFrom'] ?? '');
        $effectiveTo = (string) ($payload['effectiveTo'] ?? $effectiveFrom);

        $this->validateAdjustmentFields($type, $category, $amount, $effectiveFrom, $effectiveTo);

        $row = PayrollAdjustment::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'adjustment_no' => 'TEMP',
            'type' => $type,
            'category' => $category,
            'amount' => $amount,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'status' => PayrollAdjustment::STATUS_DRAFT,
            'description' => $payload['description'] ?? null,
        ]);

        $row->update([
            'adjustment_no' => sprintf('ADJ-%d-%04d', (int) $employee->outlet_id, (int) $row->id),
        ]);

        return $row->refresh()->load('employee');
    }

    public function update(?User $user, int $adjustmentId, array $payload): PayrollAdjustment
    {
        $row = $this->findAccessible($user, $adjustmentId);

        if ($row->status !== PayrollAdjustment::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft adjustments can be updated.'],
            ]);
        }

        $data = [];
        if (array_key_exists('type', $payload)) {
            $data['type'] = (string) $payload['type'];
        }
        if (array_key_exists('category', $payload)) {
            $data['category'] = (string) $payload['category'];
        }
        if (array_key_exists('amount', $payload)) {
            $data['amount'] = round((float) $payload['amount'], 2);
        }
        if (array_key_exists('effectiveFrom', $payload)) {
            $data['effective_from'] = (string) $payload['effectiveFrom'];
        }
        if (array_key_exists('effectiveTo', $payload)) {
            $data['effective_to'] = (string) $payload['effectiveTo'];
        }
        if (array_key_exists('description', $payload)) {
            $data['description'] = $payload['description'];
        }

        if ($data !== []) {
            $row->update($data);
            $row->refresh();
            $this->validateAdjustmentFields(
                $row->type,
                $row->category,
                (float) $row->amount,
                $row->effective_from->toDateString(),
                $row->effective_to->toDateString(),
            );
        }

        return $row->refresh()->load('employee');
    }

    public function approve(?User $user, int $adjustmentId): PayrollAdjustment
    {
        $row = $this->findAccessible($user, $adjustmentId);

        if ($row->status !== PayrollAdjustment::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft adjustments can be approved.'],
            ]);
        }

        $row->update([
            'status' => PayrollAdjustment::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $row->refresh()->load('employee');
    }

    public function cancel(?User $user, int $adjustmentId): PayrollAdjustment
    {
        $row = $this->findAccessible($user, $adjustmentId);

        if (! in_array($row->status, [PayrollAdjustment::STATUS_DRAFT, PayrollAdjustment::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or approved adjustments can be cancelled.'],
            ]);
        }

        $row->update(['status' => PayrollAdjustment::STATUS_CANCELLED]);

        return $row->refresh()->load('employee');
    }

    /**
     * Approved adjustments overlapping a payroll period for one employee.
     *
     * @return Collection<int, PayrollAdjustment>
     */
    public function applicableForEmployeeInPeriod(int $employeeId, string $periodStart, string $periodEnd): Collection
    {
        return PayrollAdjustment::query()
            ->where('employee_id', $employeeId)
            ->where('status', PayrollAdjustment::STATUS_APPROVED)
            ->where('effective_from', '<=', $periodEnd)
            ->where('effective_to', '>=', $periodStart)
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @return array{adjustmentEarning: float, adjustmentDeduction: float}
     */
    public function totalsForEmployeeInPeriod(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $rows = $this->applicableForEmployeeInPeriod($employeeId, $periodStart, $periodEnd);

        $earning = round((float) $rows->where('type', PayrollAdjustment::TYPE_EARNING)->sum('amount'), 2);
        $deduction = round((float) $rows->where('type', PayrollAdjustment::TYPE_DEDUCTION)->sum('amount'), 2);

        return [
            'adjustmentEarning' => $earning,
            'adjustmentDeduction' => $deduction,
        ];
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array{totalBonus: float, totalIncentive: float}
     */
    public function categoryTotalsForEmployeesInPeriod(
        array $employeeIds,
        string $periodStart,
        string $periodEnd,
    ): array {
        if ($employeeIds === []) {
            return ['totalBonus' => 0.0, 'totalIncentive' => 0.0];
        }

        $rows = PayrollAdjustment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', PayrollAdjustment::STATUS_APPROVED)
            ->where('type', PayrollAdjustment::TYPE_EARNING)
            ->where('effective_from', '<=', $periodEnd)
            ->where('effective_to', '>=', $periodStart)
            ->get();

        return [
            'totalBonus' => round((float) $rows->where('category', PayrollAdjustment::CATEGORY_BONUS)->sum('amount'), 2),
            'totalIncentive' => round((float) $rows->where('category', PayrollAdjustment::CATEGORY_INCENTIVE)->sum('amount'), 2),
        ];
    }

    private function validateAdjustmentFields(
        string $type,
        string $category,
        float $amount,
        string $effectiveFrom,
        string $effectiveTo,
    ): void {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be greater than zero.'],
            ]);
        }

        if (! in_array($type, [PayrollAdjustment::TYPE_EARNING, PayrollAdjustment::TYPE_DEDUCTION], true)) {
            throw ValidationException::withMessages([
                'type' => ['Type must be earning or deduction.'],
            ]);
        }

        if (! in_array($category, PayrollAdjustment::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => ['Invalid adjustment category.'],
            ]);
        }

        if ($effectiveFrom === '' || $effectiveTo === '' || $effectiveFrom > $effectiveTo) {
            throw ValidationException::withMessages([
                'effectiveFrom' => ['Effective period is invalid.'],
            ]);
        }
    }
}
