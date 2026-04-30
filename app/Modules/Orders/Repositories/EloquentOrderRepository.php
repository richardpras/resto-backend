<?php

namespace App\Modules\Orders\Repositories;

use App\Models\Modules\Orders\Domain\Order;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, array $filters = [])
    {
        $paymentStatus = $filters['payment_status'] ?? null;
        $orderType = $filters['order_type'] ?? null;
        $status = $filters['status'] ?? null;
        $source = $filters['source'] ?? null;

        return Order::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when(is_string($paymentStatus) && $paymentStatus !== '', fn ($query) => $query->where('payment_status', $paymentStatus))
            ->when(is_string($orderType) && $orderType !== '', fn ($query) => $query->where('order_type', $orderType))
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->when(is_string($source) && $source !== '', fn ($query) => $query->where('source', $source))
            ->with(['items', 'payments.allocations'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findWithRelations(int $id): ?Order
    {
        return Order::query()->with(['items', 'payments.allocations'])->find($id);
    }

    public function findById(int $id): ?Order
    {
        return Order::query()->find($id);
    }

    public function create(array $attributes): Order
    {
        return Order::query()->create($attributes);
    }

    public function update(Order $order, array $attributes): bool
    {
        return $order->update($attributes);
    }
}
