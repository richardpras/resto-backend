<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\User;
use App\Modules\UserManagement\Services\Concerns\ResolvesOutletAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PositionService
{
    use ResolvesOutletAccess;

    /**
     * @return Collection<int, Position>
     */
    public function list(?User $user, ?int $outletId, ?int $departmentId = null): Collection
    {
        $query = Position::query()
            ->with('department')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
            $query->where(function ($builder) use ($outletId): void {
                $builder->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        if ($departmentId !== null && $departmentId > 0) {
            $query->where('department_id', $departmentId);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): Position
    {
        $outletId = $payload['outletId'] ?? $payload['outlet_id'] ?? null;
        if ($outletId !== null) {
            $this->assertOutletAllowed($user, (int) $outletId);
        }

        $departmentId = $payload['departmentId'] ?? $payload['department_id'] ?? null;
        if ($departmentId !== null) {
            $this->assertDepartmentAssignable($user, (int) $departmentId, $outletId !== null ? (int) $outletId : null);
        }

        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId !== null ? (int) $outletId : null, $code);

        return Position::query()->create([
            'outlet_id' => $outletId,
            'department_id' => $departmentId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'sort_order' => (int) ($payload['sortOrder'] ?? 0),
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    public function findScoped(?User $user, int $positionId): ?Position
    {
        $position = Position::query()->with('department')->find($positionId);
        if ($position === null) {
            return null;
        }

        if ($position->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $position->outlet_id);
        }

        return $position;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, Position $position, array $payload): Position
    {
        if ($position->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $position->outlet_id);
        }

        if (isset($payload['departmentId']) || isset($payload['department_id'])) {
            $departmentId = $payload['departmentId'] ?? $payload['department_id'];
            if ($departmentId !== null) {
                $this->assertDepartmentAssignable(
                    $user,
                    (int) $departmentId,
                    $position->outlet_id !== null ? (int) $position->outlet_id : null,
                );
            }
            $position->department_id = $departmentId;
        }

        if (isset($payload['code'])) {
            $code = trim((string) $payload['code']);
            $this->assertCodeUnique(
                $position->outlet_id !== null ? (int) $position->outlet_id : null,
                $code,
                (int) $position->id,
            );
            $position->code = $code;
        }

        if (isset($payload['name'])) {
            $position->name = trim((string) $payload['name']);
        }

        if (array_key_exists('description', $payload)) {
            $position->description = $payload['description'];
        }

        if (array_key_exists('sortOrder', $payload)) {
            $position->sort_order = (int) $payload['sortOrder'];
        }

        if (array_key_exists('isActive', $payload)) {
            $position->is_active = (bool) $payload['isActive'];
        }

        $position->save();

        return $position->refresh()->load('department');
    }

    public function delete(?User $user, Position $position): void
    {
        if ($position->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $position->outlet_id);
        }

        abort_if(
            Employee::query()->where('position_id', $position->id)->exists(),
            Response::HTTP_CONFLICT,
            'Position cannot be deleted while employees are assigned.',
        );

        $position->delete();
    }

    public function assertActiveForAssignment(Position $position): void
    {
        if (! $position->is_active) {
            throw ValidationException::withMessages([
                'positionId' => ['Inactive position cannot be assigned to an employee.'],
            ]);
        }
    }

    private function assertDepartmentAssignable(?User $user, int $departmentId, ?int $outletId): void
    {
        $department = Department::query()->find($departmentId);
        abort_if($department === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Department not found.');

        if ($department->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $department->outlet_id);
            if ($outletId !== null && (int) $department->outlet_id !== $outletId) {
                throw ValidationException::withMessages([
                    'departmentId' => ['Department outlet does not match position outlet.'],
                ]);
            }
        }

        if (! $department->is_active) {
            throw ValidationException::withMessages([
                'departmentId' => ['Inactive department cannot be assigned.'],
            ]);
        }
    }

    private function assertCodeUnique(?int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = Position::query()
            ->when($outletId === null, fn ($q) => $q->whereNull('outlet_id'), fn ($q) => $q->where('outlet_id', $outletId))
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Position code must be unique for this outlet scope.'],
            ]);
        }
    }
}
