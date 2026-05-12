<?php

namespace App\Modules\Orders\Repositories;

use App\Models\Modules\Orders\Domain\Order;

class EloquentOrderRepository implements OrderRepositoryInterface
{
    public function paginateByTenant(int $tenantId, int $perPage = 20, array $filters = [])
    {
        $paymentStatus = $filters['payment_status'] ?? null;
        $orderType = $filters['order_type'] ?? null;
        $serviceMode = $filters['service_mode'] ?? null;
        $kitchenStatus = $filters['kitchen_status'] ?? null;
        $status = $filters['status'] ?? null;
        $source = $filters['source'] ?? null;
        $outletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        $allowedOutletIds = $filters['allowed_outlet_ids'] ?? null;
        $search = $filters['search'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $hasVoidedPayment = $filters['has_voided_payment'] ?? null;

        return Order::query()
            ->when($tenantId > 0, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($outletId !== null && $outletId > 0, fn ($query) => $query->where('outlet_id', $outletId))
            ->when(
                is_array($allowedOutletIds),
                fn ($query) => $query->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds),
            )
            ->when(is_string($paymentStatus) && $paymentStatus !== '', fn ($query) => $query->where('payment_status', $paymentStatus))
            ->when(is_string($orderType) && $orderType !== '', fn ($query) => $query->where('order_type', $orderType))
            ->when(is_string($serviceMode) && $serviceMode !== '', fn ($query) => $query->where('service_mode', $serviceMode))
            ->when(is_string($kitchenStatus) && $kitchenStatus !== '', fn ($query) => $query->where('kitchen_status', $kitchenStatus))
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->when(is_string($source) && $source !== '', fn ($query) => $query->where('source', $source))
            ->when(is_string($search) && trim($search) !== '', function ($query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where('code', 'like', $term);
            })
            ->when($dateFrom !== null && $dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null && $dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->when($hasVoidedPayment === true || $hasVoidedPayment === 1 || $hasVoidedPayment === '1' || $hasVoidedPayment === 'true',
                fn ($query) => $query->whereHas(
                    'payments',
                    fn ($q) => $q->where('status', 'void')
                ))
            ->with(['items', 'payments.allocations', 'splits.items'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findWithRelations(int $id): ?Order
    {
        return Order::query()->with(['items', 'payments.allocations', 'splits.items'])->find($id);
    }

    public function findById(int $id): ?Order
    {
        return Order::query()->find($id);
    }

    /** @param list<int> $allowedOutletIds */
    public function findScoped(int $id, array $allowedOutletIds): ?Order
    {
        return Order::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($id)
            ->with(['items', 'payments.allocations', 'splits.items'])
            ->first();
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
