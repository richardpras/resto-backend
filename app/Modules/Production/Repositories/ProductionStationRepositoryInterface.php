<?php

namespace App\Modules\Production\Repositories;

use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Support\Collection;

interface ProductionStationRepositoryInterface
{
    /**
     * @return Collection<int, ProductionStation>
     */
    public function listForOutlet(int $outletId, bool $activeOnly = false): Collection;

    public function findById(int $id): ?ProductionStation;

    public function findByOutletAndCode(int $outletId, string $code): ?ProductionStation;

    public function create(array $attributes): ProductionStation;

    public function update(ProductionStation $station, array $attributes): bool;
}
