<?php

namespace App\Modules\Inventory\Repositories;

interface StockMovementRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, ?int $outletId = null);

    public function create(array $attributes);
}
