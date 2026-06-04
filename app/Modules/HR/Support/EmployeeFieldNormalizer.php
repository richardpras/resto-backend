<?php

namespace App\Modules\HR\Support;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;

/**
 * Keeps legacy string fields aligned with FK columns on the single employees master row.
 */
class EmployeeFieldNormalizer
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalizeAttributes(array $attributes): array
    {
        if (array_key_exists('outletId', $attributes) || array_key_exists('outlet_id', $attributes)) {
            $outletId = $attributes['outletId'] ?? $attributes['outlet_id'] ?? null;
            if ($outletId !== null) {
                $attributes['outlet_id'] = (int) $outletId;
                $outlet = Outlet::query()->find((int) $outletId);
                if ($outlet !== null) {
                    $attributes['outlet'] = (string) $outlet->name;
                }
            }
        }

        if (array_key_exists('outlet', $attributes) && ($attributes['outlet_id'] ?? null) === null) {
            $label = trim((string) $attributes['outlet']);
            if ($label !== '') {
                $outlet = Outlet::query()
                    ->where('name', $label)
                    ->orWhere('code', $label)
                    ->first();
                if ($outlet !== null) {
                    $attributes['outlet_id'] = (int) $outlet->id;
                }
            }
        }

        if (array_key_exists('positionId', $attributes) || array_key_exists('position_id', $attributes)) {
            $positionId = $attributes['positionId'] ?? $attributes['position_id'] ?? null;
            if ($positionId !== null) {
                $attributes['position_id'] = (int) $positionId;
                $position = Position::query()->find((int) $positionId);
                if ($position !== null) {
                    $attributes['position'] = (string) $position->name;
                }
            }
        }

        if (
            array_key_exists('position', $attributes)
            && trim((string) ($attributes['position'] ?? '')) !== ''
            && ($attributes['position_id'] ?? null) === null
        ) {
            $label = trim((string) $attributes['position']);
            $position = Position::query()->where('name', $label)->first();
            if ($position !== null) {
                $attributes['position_id'] = (int) $position->id;
            }
        }

        return $attributes;
    }

    public function syncStoredRow(Employee $employee): Employee
    {
        $dirty = false;

        if ($employee->outlet_id !== null) {
            $outlet = Outlet::query()->find((int) $employee->outlet_id);
            if ($outlet !== null && $employee->outlet !== $outlet->name) {
                $employee->outlet = (string) $outlet->name;
                $dirty = true;
            }
        } elseif ($employee->outlet !== null && trim((string) $employee->outlet) !== '') {
            $outlet = Outlet::query()
                ->where('name', $employee->outlet)
                ->orWhere('code', $employee->outlet)
                ->first();
            if ($outlet !== null && $employee->outlet_id !== (int) $outlet->id) {
                $employee->outlet_id = (int) $outlet->id;
                $dirty = true;
            }
        }

        if ($employee->position_id !== null) {
            $position = Position::query()->find((int) $employee->position_id);
            if ($position !== null && $employee->position !== $position->name) {
                $employee->position = (string) $position->name;
                $dirty = true;
            }
        }

        if ($dirty) {
            $employee->save();
        }

        return $employee->refresh();
    }
}
