<?php

namespace App\Modules\Settings\Support;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;

class OutletAccessResolver
{
    /** @return list<int> */
    public function allowedOutletIds(User $user): array
    {
        // `outlets.view_all` is explicit; `dashboard.view_all_outlets` matches template Owner (all perms)
        // when `outlets.view_all` was missing from early seeds — both imply tenant-wide outlet scope.
        if ($user->hasPermission('outlets.view_all') || $user->hasPermission('dashboard.view_all_outlets')) {
            return Outlet::query()
                ->where('status', 'active')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        return $user->outlets()
            ->where('status', 'active')
            ->pluck('outlets.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public function scopedOutletPayload(User $user): array
    {
        $allowedIds = $this->allowedOutletIds($user);
        if ($allowedIds === []) {
            return [];
        }

        return Outlet::query()
            ->whereIn('id', $allowedIds)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(static fn (Outlet $outlet): array => [
                'id' => (int) $outlet->id,
                'code' => (string) ($outlet->code ?? ''),
                'name' => (string) $outlet->name,
            ])
            ->values()
            ->all();
    }
}
