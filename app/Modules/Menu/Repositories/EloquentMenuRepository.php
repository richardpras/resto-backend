<?php

namespace App\Modules\Menu\Repositories;

use App\Models\Modules\Menu\Domain\MenuItem;

class EloquentMenuRepository implements MenuRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, ?int $outletId = null)
    {
        return MenuItem::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(
                $outletId !== null && $outletId >= 1,
                fn ($query) => $query->whereHas(
                    'outletMappings',
                    fn ($mapping) => $mapping->where('outlet_id', $outletId)->where('is_active', true)
                )
            )
            ->with(['recipes.ingredient', 'outletMappings'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findById(int $id): ?MenuItem
    {
        return MenuItem::query()->find($id);
    }

    public function findWithRecipes(int $id): ?MenuItem
    {
        return MenuItem::query()->with(['recipes.ingredient', 'outletMappings'])->find($id);
    }

    public function create(array $attributes): MenuItem
    {
        return MenuItem::query()->create($attributes);
    }

    public function update(MenuItem $menuItem, array $attributes): bool
    {
        return $menuItem->update($attributes);
    }
}
