<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LeaveTypeService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return Collection<int, LeaveType>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = LeaveType::query()->orderBy('name');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        } elseif ($user !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if ($allowed !== []) {
                $query->whereIn('outlet_id', $allowed);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (isset($filters['isActive'])) {
            $query->where('is_active', filter_var($filters['isActive'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->get();
    }

    public function create(?User $user, array $payload): LeaveType
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        $this->assertOutletAllowed($user, $outletId);

        $code = strtolower(trim((string) ($payload['code'] ?? '')));
        abort_if($code === '', 422, 'code is required.');

        if (LeaveType::query()->where('outlet_id', $outletId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['Leave type code already exists for this outlet.'],
            ]);
        }

        return LeaveType::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => (string) ($payload['name'] ?? $code),
            'requires_attachment' => (bool) ($payload['requiresAttachment'] ?? false),
            'deduct_leave_balance' => (bool) ($payload['deductLeaveBalance'] ?? true),
            'paid_leave' => (bool) ($payload['paidLeave'] ?? true),
            'is_active' => (bool) ($payload['isActive'] ?? true),
        ]);
    }

    public function update(?User $user, int $id, array $payload): LeaveType
    {
        $type = $this->findAccessible($user, $id);

        if (array_key_exists('name', $payload)) {
            $type->name = (string) $payload['name'];
        }
        if (array_key_exists('requiresAttachment', $payload)) {
            $type->requires_attachment = (bool) $payload['requiresAttachment'];
        }
        if (array_key_exists('deductLeaveBalance', $payload)) {
            $type->deduct_leave_balance = (bool) $payload['deductLeaveBalance'];
        }
        if (array_key_exists('paidLeave', $payload)) {
            $type->paid_leave = (bool) $payload['paidLeave'];
        }
        if (array_key_exists('isActive', $payload)) {
            $type->is_active = (bool) $payload['isActive'];
        }

        $type->save();

        return $type->refresh();
    }

    public function findAccessible(?User $user, int $id): LeaveType
    {
        $type = LeaveType::query()->find($id);
        abort_if($type === null, Response::HTTP_NOT_FOUND, 'Leave type not found.');

        $this->assertOutletAllowed($user, (int) $type->outlet_id);

        return $type;
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null || $outletId < 1) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot manage leave types for this outlet.');
        }
    }
}
