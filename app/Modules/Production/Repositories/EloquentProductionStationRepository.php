<?php

namespace App\Modules\Production\Repositories;

use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Support\Collection;

class EloquentProductionStationRepository implements ProductionStationRepositoryInterface
{
    public function listForOutlet(int $outletId, bool $activeOnly = false): Collection
    {
        return ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function findById(int $id): ?ProductionStation
    {
        return ProductionStation::query()->find($id);
    }

    public function findByOutletAndCode(int $outletId, string $code): ?ProductionStation
    {
        return ProductionStation::query()
            ->where('outlet_id', $outletId)
            ->where('code', strtolower($code))
            ->first();
    }

    public function create(array $attributes): ProductionStation
    {
        return ProductionStation::query()->create($attributes);
    }

    public function update(ProductionStation $station, array $attributes): bool
    {
        return $station->update($attributes);
    }
}
