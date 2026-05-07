<?php

namespace App\Modules\Orders\Repositories;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QrOrderRequestRepositoryInterface
{
    public function create(array $attributes): QrOrderRequest;

    public function update(QrOrderRequest $request, array $attributes): bool;

    /** @param list<int> $allowedOutletIds */
    public function findScoped(int $id, array $allowedOutletIds): ?QrOrderRequest;

    /** @param list<int> $allowedOutletIds */
    public function paginateScoped(int $perPage, array $allowedOutletIds, array $filters = []): LengthAwarePaginator;
}
