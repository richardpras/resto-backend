<?php

namespace App\Modules\Inventory\Repositories;

use App\Models\Modules\Inventory\Domain\StockMovement;

class EloquentStockMovementRepository implements StockMovementRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20)
    {
        return StockMovement::query()
            ->whereHas('ingredient', fn ($query) => $query->where('tenant_id', $tenantId))
            ->with('ingredient')
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $attributes)
    {
        return StockMovement::query()->create($attributes);
    }
}
