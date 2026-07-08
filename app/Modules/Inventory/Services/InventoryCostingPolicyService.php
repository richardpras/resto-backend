<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Support\Facades\DB;

final class InventoryCostingPolicyService
{
    public function getMethod(): string
    {
        $system = SystemSetting::query()->first();
        if ($system !== null && is_string($system->inventory_costing_method) && $system->inventory_costing_method !== '') {
            return InventoryCostingMethod::normalize($system->inventory_costing_method);
        }

        $configMethod = config('inventory.costing_method');
        if (is_string($configMethod) && $configMethod !== '') {
            return InventoryCostingMethod::normalize($configMethod);
        }

        return InventoryCostingMethod::MOVING_AVERAGE;
    }

    public function costMethodLabel(): string
    {
        return $this->getMethod();
    }

    public function hasInventoryActivity(): bool
    {
        if (DB::table('stock_movements')->exists()) {
            return true;
        }

        return DB::table('inventory_valuations')
            ->where(function ($query): void {
                $query->where('stock_quantity', '>', 0)
                    ->orWhere('inventory_value', '>', 0);
            })
            ->exists();
    }

    public function assertMethodChangeAllowed(string $currentMethod, string $nextMethod, bool $forceRecalculate): void
    {
        if ($currentMethod === $nextMethod) {
            return;
        }

        if ($forceRecalculate) {
            return;
        }

        if ($this->hasInventoryActivity()) {
            abort(
                422,
                'Changing inventory costing method requires forceRecalculateOnMethodChange when inventory activity exists.',
            );
        }
    }
}
