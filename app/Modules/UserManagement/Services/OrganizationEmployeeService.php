<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\User;
use App\Modules\HR\Support\EmployeeFieldNormalizer;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Modules\UserManagement\Services\Concerns\ResolvesOutletAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OrganizationEmployeeService
{
    use ResolvesOutletAccess;

    public function __construct(
        private readonly PositionService $positionService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly EmployeeFieldNormalizer $fieldNormalizer,
    ) {}

    /**
     * @return Collection<int, Employee>
     */
    public function list(?User $user, int $outletId, ?string $search = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = Employee::query()
            ->with(['positionRelation', 'department', 'outletRelation', 'user'])
            ->where('outlet_id', $outletId)
            ->orderBy('full_name');

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, trim($search));
        }

        return $query->get();
    }

    public function search(?User $user, int $outletId, string $term, int $limit = 20): Collection
    {
        $this->assertOutletAllowed($user, $outletId);
        $query = Employee::query()
            ->with(['positionRelation', 'department', 'user'])
            ->where('outlet_id', $outletId)
            ->orderBy('full_name')
            ->limit($limit);

        $this->applySearch($query, trim($term));

        return $query->get();
    }

    public function findScoped(?User $user, int $employeeId): ?Employee
    {
        $employee = Employee::query()
            ->with(['positionRelation', 'department', 'outletRelation', 'user'])
            ->find($employeeId);

        if ($employee === null || $employee->outlet_id === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $employee->outlet_id);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): Employee
    {
        $outletId = (int) ($payload['outletId'] ?? $payload['outlet_id'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }
        $this->assertOutletAllowed($user, $outletId);

        $employeeNo = trim((string) ($payload['employeeNo'] ?? $payload['employee_no'] ?? ''));
        if ($employeeNo === '') {
            $employeeNo = $this->generateEmployeeNo($outletId);
        }
        $this->assertEmployeeNoUnique($outletId, $employeeNo);

        $fullName = trim((string) ($payload['fullName'] ?? $payload['name'] ?? ''));
        if ($fullName === '') {
            throw ValidationException::withMessages([
                'fullName' => ['Full name is required.'],
            ]);
        }

        [$positionId, $departmentId, $positionLabel] = $this->resolvePositionAndDepartment($user, $payload, $outletId);

        $employee = Employee::query()->create(
            $this->fieldNormalizer->normalizeAttributes([
                'outlet_id' => $outletId,
                'employee_no' => $employeeNo,
                'full_name' => $fullName,
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'gender' => $payload['gender'] ?? null,
                'birth_date' => $payload['birthDate'] ?? $payload['birth_date'] ?? null,
                'hire_date' => $payload['hireDate'] ?? $payload['hire_date'] ?? null,
                'status' => $payload['status'] ?? Employee::STATUS_ACTIVE,
                'position_id' => $positionId,
                'department_id' => $departmentId,
                'position' => $positionLabel,
                'base_salary' => $payload['baseSalary'] ?? 0,
                'user_id' => null,
                'notes' => $payload['notes'] ?? null,
            ]),
        );

        return $this->fieldNormalizer->syncStoredRow($employee)
            ->load(['positionRelation', 'department', 'outletRelation', 'user']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, Employee $employee, array $payload): Employee
    {
        $this->assertOutletAllowed($user, (int) $employee->outlet_id);

        if (isset($payload['employeeNo']) || isset($payload['employee_no'])) {
            $employeeNo = trim((string) ($payload['employeeNo'] ?? $payload['employee_no']));
            $this->assertEmployeeNoUnique((int) $employee->outlet_id, $employeeNo, (int) $employee->id);
            $employee->employee_no = $employeeNo;
        }

        if (isset($payload['fullName']) || isset($payload['name'])) {
            $employee->full_name = trim((string) ($payload['fullName'] ?? $payload['name']));
        }

        foreach (['email', 'phone', 'gender', 'notes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $employee->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('birthDate', $payload) || array_key_exists('birth_date', $payload)) {
            $employee->birth_date = $payload['birthDate'] ?? $payload['birth_date'];
        }

        if (array_key_exists('hireDate', $payload) || array_key_exists('hire_date', $payload)) {
            $employee->hire_date = $payload['hireDate'] ?? $payload['hire_date'];
        }

        if (array_key_exists('status', $payload)) {
            $employee->status = $payload['status'];
        }

        if (
            array_key_exists('positionId', $payload)
            || array_key_exists('position_id', $payload)
            || array_key_exists('departmentId', $payload)
            || array_key_exists('department_id', $payload)
        ) {
            [$positionId, $departmentId, $positionLabel] = $this->resolvePositionAndDepartment(
                $user,
                $payload,
                (int) $employee->outlet_id,
                $employee,
            );
            $employee->position_id = $positionId;
            $employee->department_id = $departmentId;
            $employee->position = $positionLabel;
        }

        $employee->save();

        return $this->fieldNormalizer->syncStoredRow($employee)
            ->load(['positionRelation', 'department', 'outletRelation', 'user']);
    }

    public function assignUser(?User $actor, Employee $employee, int $userId): Employee
    {
        $this->assertOutletAllowed($actor, (int) $employee->outlet_id);

        $targetUser = User::query()->with('outlets')->find($userId);
        abort_if($targetUser === null, Response::HTTP_NOT_FOUND, 'User not found.');

        $this->assertUserBelongsToOutlet($targetUser, (int) $employee->outlet_id);

        $existing = Employee::query()
            ->where('user_id', $userId)
            ->where('id', '!=', $employee->id)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'userId' => ['User is already linked to another employee record.'],
            ]);
        }

        $employee->user_id = $userId;
        $employee->save();

        return $employee->refresh()->load(['positionRelation', 'department', 'outletRelation', 'user']);
    }

    public function removeUser(?User $actor, Employee $employee): Employee
    {
        $this->assertOutletAllowed($actor, (int) $employee->outlet_id);
        $employee->user_id = null;
        $employee->save();

        return $employee->refresh()->load(['positionRelation', 'department', 'outletRelation', 'user']);
    }

    private function applySearch(Builder $query, string $needle): void
    {
        $like = '%'.$needle.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('full_name', 'like', $like)
                ->orWhere('employee_no', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    private function resolvePositionAndDepartment(
        ?User $user,
        array $payload,
        int $outletId,
        ?Employee $existing = null,
    ): array {
        $positionId = $payload['positionId'] ?? $payload['position_id'] ?? $existing?->position_id;
        $departmentId = $payload['departmentId'] ?? $payload['department_id'] ?? $existing?->department_id;

        $positionLabel = $existing?->position ?? '';

        if ($positionId !== null) {
            $position = Position::query()->find((int) $positionId);
            abort_if($position === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Position not found.');
            $this->positionService->assertActiveForAssignment($position);
            if ($position->outlet_id !== null && (int) $position->outlet_id !== $outletId) {
                throw ValidationException::withMessages([
                    'positionId' => ['Position does not belong to this outlet.'],
                ]);
            }
            $positionLabel = $position->name;
            if ($departmentId === null && $position->department_id !== null) {
                $departmentId = $position->department_id;
            }
        }

        if ($departmentId !== null) {
            $department = Department::query()->find((int) $departmentId);
            abort_if($department === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Department not found.');
            if (! $department->is_active) {
                throw ValidationException::withMessages([
                    'departmentId' => ['Inactive department cannot be assigned.'],
                ]);
            }
            if ($department->outlet_id !== null && (int) $department->outlet_id !== $outletId) {
                throw ValidationException::withMessages([
                    'departmentId' => ['Department does not belong to this outlet.'],
                ]);
            }
        }

        return [$positionId !== null ? (int) $positionId : null, $departmentId !== null ? (int) $departmentId : null, $positionLabel];
    }

    private function assertEmployeeNoUnique(int $outletId, string $employeeNo, ?int $ignoreId = null): void
    {
        $exists = Employee::query()
            ->where('outlet_id', $outletId)
            ->where('employee_no', $employeeNo)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'employeeNo' => ['Employee number must be unique for this outlet.'],
            ]);
        }
    }

    private function assertUserBelongsToOutlet(User $targetUser, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($targetUser);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'userId' => ['Cannot assign user from another outlet.'],
            ]);
        }
    }

    private function generateEmployeeNo(int $outletId): string
    {
        $prefix = 'EMP-'.$outletId.'-';
        $latest = Employee::query()
            ->where('outlet_id', $outletId)
            ->where('employee_no', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('employee_no');

        $seq = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
