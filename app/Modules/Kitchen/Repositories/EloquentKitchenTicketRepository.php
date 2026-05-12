<?php

namespace App\Modules\Kitchen\Repositories;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentKitchenTicketRepository implements KitchenTicketRepositoryInterface
{
    public function paginateByOutletScope(int $perPage, array $allowedOutletIds, array $filters = []): LengthAwarePaginator
    {
        $outletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        $status = $filters['status'] ?? null;

        return KitchenTicket::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->when($outletId !== null && $outletId > 0, fn ($query) => $query->where('outlet_id', $outletId))
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->with(['items.orderItem'])
            ->latest('id')
            ->paginate($perPage);
    }

    public function findScoped(int $id, array $allowedOutletIds): ?KitchenTicket
    {
        return KitchenTicket::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($id)
            ->with(['items.orderItem'])
            ->first();
    }

    public function findByOrderId(int $orderId): ?KitchenTicket
    {
        return KitchenTicket::query()->where('order_id', $orderId)->with(['items.orderItem'])->first();
    }

    public function create(array $attributes): KitchenTicket
    {
        return KitchenTicket::query()->create($attributes);
    }

    public function update(KitchenTicket $ticket, array $attributes): bool
    {
        return $ticket->update($attributes);
    }
}
