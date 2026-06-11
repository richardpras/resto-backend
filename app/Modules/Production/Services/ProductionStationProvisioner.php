<?php

namespace App\Modules\Production\Services;

use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Production\Support\DefaultProductionStationCatalog;
use Illuminate\Support\Collection;

class ProductionStationProvisioner
{
    /**
     * @param  list<string>|null  $codes  When null, seeds all default stations.
     * @return Collection<int, ProductionStation>
     */
    public function ensureForOutlet(Outlet $outlet, ?array $codes = null, ?int $tenantId = null): Collection
    {
        $definitions = $codes === null
            ? DefaultProductionStationCatalog::all()
            : DefaultProductionStationCatalog::forCodes($codes);

        $stations = collect();
        foreach ($definitions as $definition) {
            $stations->push(
                ProductionStation::query()->updateOrCreate(
                    [
                        'outlet_id' => (int) $outlet->id,
                        'code' => strtolower((string) $definition['code']),
                    ],
                    [
                        'tenant_id' => $tenantId,
                        'name' => (string) $definition['name'],
                        'type' => (string) $definition['type'],
                        'display_order' => (int) $definition['display_order'],
                        'is_active' => true,
                        'kds_enabled' => (bool) $definition['kds_enabled'],
                        'print_enabled' => (bool) $definition['print_enabled'],
                    ],
                )
            );
        }

        return $stations;
    }
}
