<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicQrMenuService
{
    /**
     * @return Collection<int, MenuItem>
     */
    public function listForQrPublicId(string $qrPublicId): Collection
    {
        $table = RestaurantTable::query()
            ->where('qr_public_id', $qrPublicId)
            ->where('qr_enabled', true)
            ->where('active', true)
            ->first();

        if ($table === null) {
            throw (new ModelNotFoundException)->setModel(RestaurantTable::class, [$qrPublicId]);
        }

        return MenuItem::query()
            ->where('available', true)
            ->whereHas(
                'outletMappings',
                fn ($mapping) => $mapping
                    ->where('outlet_id', (int) $table->outlet_id)
                    ->where('is_active', true)
            )
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }
}
