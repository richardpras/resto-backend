<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Shift;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\Response;

class ShiftService
{
    public function listByTenant(int $tenantId)
    {
        return Shift::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->latest('id')
            ->get();
    }

    public function create(array $payload): Shift
    {
        return Shift::query()->create($this->normalizePayload($payload));
    }

    public function update(int $shiftId, array $payload): Shift
    {
        $shift = Shift::query()->find($shiftId);
        abort_if($shift === null, Response::HTTP_NOT_FOUND, 'Shift not found.');

        $shift->fill($this->normalizePayload($payload))->save();

        return $shift->refresh();
    }

    public function delete(int $shiftId): void
    {
        $shift = Shift::query()->find($shiftId);
        abort_if($shift === null, Response::HTTP_NOT_FOUND, 'Shift not found.');
        try {
            $shift->delete();
        } catch (QueryException $exception) {
            abort(Response::HTTP_CONFLICT, 'Shift cannot be deleted while attendance records exist.');
        }
    }

    private function normalizePayload(array $payload): array
    {
        return [
            'tenant_id' => $payload['tenantId'] ?? null,
            'code' => $payload['code'],
            'name' => $payload['name'],
            'start_time' => $payload['startTime'].':00',
            'end_time' => $payload['endTime'].':00',
            'late_tolerance_minutes' => $payload['lateToleranceMinutes'] ?? 0,
            'overtime_after_minutes' => $payload['overtimeAfterMinutes'] ?? 0,
            'active' => $payload['active'] ?? true,
        ];
    }
}
