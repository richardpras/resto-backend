<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\User;
use App\Modules\UserManagement\Services\Concerns\ResolvesOutletAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class DepartmentService
{
    use ResolvesOutletAccess;

    /**
     * @return Collection<int, Department>
     */
    public function list(?User $user, ?int $outletId): Collection
    {
        $query = Department::query()->orderBy('name');

        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
            $query->where(function ($builder) use ($outletId): void {
                $builder->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): Department
    {
        $outletId = $payload['outletId'] ?? $payload['outlet_id'] ?? null;
        if ($outletId !== null) {
            $this->assertOutletAllowed($user, (int) $outletId);
        }

        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId !== null ? (int) $outletId : null, $code);

        return Department::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    public function findScoped(?User $user, int $departmentId): ?Department
    {
        $department = Department::query()->find($departmentId);
        if ($department === null) {
            return null;
        }

        if ($department->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $department->outlet_id);
        }

        return $department;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, Department $department, array $payload): Department
    {
        if ($department->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $department->outlet_id);
        }

        $outletId = array_key_exists('outletId', $payload)
            ? $payload['outletId']
            : (array_key_exists('outlet_id', $payload) ? $payload['outlet_id'] : $department->outlet_id);

        if ($outletId !== null) {
            $this->assertOutletAllowed($user, (int) $outletId);
        }

        if (isset($payload['code'])) {
            $code = trim((string) $payload['code']);
            $this->assertCodeUnique($outletId !== null ? (int) $outletId : null, $code, (int) $department->id);
            $department->code = $code;
        }

        if (isset($payload['name'])) {
            $department->name = trim((string) $payload['name']);
        }

        if (array_key_exists('description', $payload)) {
            $department->description = $payload['description'];
        }

        if (array_key_exists('isActive', $payload)) {
            $department->is_active = (bool) $payload['isActive'];
        }

        if (array_key_exists('outletId', $payload) || array_key_exists('outlet_id', $payload)) {
            $department->outlet_id = $outletId;
        }

        $department->save();

        return $department->refresh();
    }

    public function delete(?User $user, Department $department): void
    {
        if ($department->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $department->outlet_id);
        }

        abort_if(
            Employee::query()->where('department_id', $department->id)->exists(),
            Response::HTTP_CONFLICT,
            'Department cannot be deleted while employees are assigned.',
        );

        $department->delete();
    }

    private function assertCodeUnique(?int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = Department::query()
            ->when($outletId === null, fn ($q) => $q->whereNull('outlet_id'), fn ($q) => $q->where('outlet_id', $outletId))
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Department code must be unique for this outlet scope.'],
            ]);
        }
    }
}
