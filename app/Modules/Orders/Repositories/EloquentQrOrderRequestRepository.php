<?php

namespace App\Modules\Orders\Repositories;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentQrOrderRequestRepository implements QrOrderRequestRepositoryInterface
{
    public function create(array $attributes): QrOrderRequest
    {
        return QrOrderRequest::query()->create($attributes);
    }

    public function update(QrOrderRequest $request, array $attributes): bool
    {
        return $request->update($attributes);
    }

    /** @param list<int> $allowedOutletIds */
    public function findScoped(int $id, array $allowedOutletIds): ?QrOrderRequest
    {
        return QrOrderRequest::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->whereKey($id)
            ->with(['items.menuItem', 'table', 'order'])
            ->first();
    }

    /** @param list<int> $allowedOutletIds */
    public function paginateScoped(int $perPage, array $allowedOutletIds, array $filters = []): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $outletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        $search = $filters['search'] ?? null;

        return QrOrderRequest::query()
            ->whereIn('outlet_id', $allowedOutletIds === [] ? [-1] : $allowedOutletIds)
            ->when($outletId !== null && $outletId > 0, fn ($query) => $query->where('outlet_id', $outletId))
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->when(is_string($search) && trim($search) !== '', function ($query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->where('request_code', 'like', $term)
                        ->orWhereHas('table', fn ($tableQuery) => $tableQuery->where('name', 'like', $term))
                        ->orWhereHas('order', function ($orderQuery) use ($term): void {
                            $orderQuery
                                ->where('code', 'like', $term)
                                ->orWhere('source_code', 'like', $term);
                        });
                });
            })
            ->with(['items.menuItem', 'table', 'order'])
            ->when(
                $status === 'pending_cashier_confirmation',
                function ($query) {
                    $query
                        ->orderByDesc('cashier_call_count')
                        ->orderByDesc('cashier_called_at')
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
                fn ($query) => $query->latest('id')
            )
            ->paginate($perPage);
    }
}
