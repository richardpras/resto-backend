<?php

namespace App\Modules\Inventory\Repositories;

use App\Models\Modules\Inventory\Domain\StockMovement;

class EloquentStockMovementRepository implements StockMovementRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, ?int $outletId = null, ?array $allowedOutletIds = null)
    {
        return StockMovement::query()
            ->whereHas('ingredient', fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($allowedOutletIds !== null, fn ($query) => $query->whereIn('outlet_id', $allowedOutletIds))
            ->with('ingredient')
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $attributes)
    {
        return StockMovement::query()->create($attributes);
    }
}
