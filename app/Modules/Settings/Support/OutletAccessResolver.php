<?php

namespace App\Modules\Settings\Support;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;

class OutletAccessResolver
{
    /** @return list<int> */
    public function allowedOutletIds(User $user): array
    {
        if ($user->isSuperAdmin()) {
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
