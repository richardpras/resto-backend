<?php

namespace App\Modules\Orders\Repositories;

use App\Models\Modules\Orders\Domain\Order;

interface OrderRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, array $filters = []);

    public function findWithRelations(int $id): ?Order;

    public function findById(int $id): ?Order;

    /**
     * Find an order by id only when its outlet is in the supplied allow-list.
     *
     * @param  list<int>  $allowedOutletIds
     */
    public function findScoped(int $id, array $allowedOutletIds): ?Order;

    public function create(array $attributes): Order;

    public function update(Order $order, array $attributes): bool;
}
