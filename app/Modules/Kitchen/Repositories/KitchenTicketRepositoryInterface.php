<?php

namespace App\Modules\Kitchen\Repositories;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface KitchenTicketRepositoryInterface
{
    /** @param array<string,mixed> $filters */
    public function paginateByOutletScope(int $perPage, array $allowedOutletIds, array $filters = []): LengthAwarePaginator;

    /** @param list<int> $allowedOutletIds */
    public function findScoped(int $id, array $allowedOutletIds): ?KitchenTicket;

    public function findByOrderId(int $orderId): ?KitchenTicket;

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): KitchenTicket;

    /** @param array<string,mixed> $attributes */
    public function update(KitchenTicket $ticket, array $attributes): bool;
}
