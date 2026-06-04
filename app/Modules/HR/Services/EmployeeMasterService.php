<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\HR\Support\EmployeeFieldNormalizer;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical employee master access — single {@see Employee} entity for all HRM modules.
 */
class EmployeeMasterService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly EmployeeFieldNormalizer $fieldNormalizer,
    ) {}

    public function findOrFail(int $employeeId): Employee
    {
        $employee = Employee::query()->find($employeeId);
        abort_if($employee === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        return $employee;
    }

    public function findAccessible(?User $user, int $employeeId): Employee
    {
        $employee = $this->findOrFail($employeeId);
        $this->assertEmployeeOutletAllowed($user, $employee);

        return $employee;
    }

    /**
     * @return Builder<Employee>
     */
    public function scopedEmployeeQuery(?User $user): Builder
    {
        $query = Employee::query();

        return $this->applyOutletScopeToEmployeeQuery($query, $user);
    }

    /**
     * Restrict child HRM rows to employees the user may access (via outlet_id).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeByEmployeeOutlet(Builder $query, ?User $user, string $employeeForeignKey = 'employee_id'): Builder
    {
        if ($user === null) {
            return $query;
        }

        return $query->whereIn(
            $employeeForeignKey,
            $this->scopedEmployeeQuery($user)->select('employees.id'),
        );
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function applyOutletScopeToEmployeeQuery(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query;
        }

        $allowedIds = $this->allowedOutletIds($user);
        if ($allowedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('outlet_id', $allowedIds);
    }

    /**
     * Payroll run still filters by legacy outlet label; include FK matches without changing payroll math.
     *
     * @param  Builder<Employee>  $query
     */
    public function applyPayrollOutletLabelFilter(Builder $query, ?string $outletLabel): Builder
    {
        if ($outletLabel === null || trim($outletLabel) === '') {
            return $query;
        }

        $label = trim($outletLabel);
        $outletId = Outlet::query()
            ->where('name', $label)
            ->orWhere('code', $label)
            ->value('id');

        return $query->where(function (Builder $inner) use ($label, $outletId): void {
            $inner->where('outlet', $label);
            if ($outletId !== null) {
                $inner->orWhere('outlet_id', (int) $outletId);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalizeAttributes(array $attributes): array
    {
        return $this->fieldNormalizer->normalizeAttributes($attributes);
    }

    public function syncStoredRow(Employee $employee): Employee
    {
        return $this->fieldNormalizer->syncStoredRow($employee);
    }

    public function assertEmployeeOutletAllowed(?User $user, Employee $employee): void
    {
        if ($user === null) {
            return;
        }

        if ($employee->outlet_id === null) {
            if ($this->hasTenantWideOutletAccess($user)) {
                return;
            }

            throw ValidationException::withMessages([
                'employeeId' => ['Employee outlet is not assigned.'],
            ]);
        }

        $allowed = $this->allowedOutletIds($user);
        if (! in_array((int) $employee->outlet_id, $allowed, true)) {
            throw ValidationException::withMessages([
                'employeeId' => ['Employee belongs to an outlet you cannot access.'],
            ]);
        }
    }

    private function hasTenantWideOutletAccess(User $user): bool
    {
        return $user->hasPermission('outlets.view_all')
            || $user->hasPermission('dashboard.view_all_outlets');
    }

    /** @return list<int> */
    private function allowedOutletIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $this->outletAccessResolver->allowedOutletIds($user);
    }
}
