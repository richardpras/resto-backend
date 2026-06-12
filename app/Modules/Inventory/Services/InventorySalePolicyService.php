<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\StockEnforcementMode;
use Illuminate\Support\Facades\DB;

class InventorySalePolicyService
{
    public function getMode(?int $outletId): string
    {
        if ($outletId !== null && $outletId > 0) {
            $override = DB::table('outlet_inventory_settings')
                ->where('outlet_id', $outletId)
                ->value('stock_enforcement_mode');
            if ($override !== null && $override !== '') {
                return StockEnforcementMode::normalize((string) $override);
            }

            $legacyOverride = DB::table('outlet_inventory_settings')
                ->where('outlet_id', $outletId)
                ->value('enforce_stock_on_sale');
            if ($legacyOverride !== null) {
                return StockEnforcementMode::fromLegacyBoolean((bool) $legacyOverride);
            }
        }

        $system = SystemSetting::query()->first();
        if ($system !== null) {
            $mode = $system->stock_enforcement_mode ?? null;
            if (is_string($mode) && $mode !== '') {
                return StockEnforcementMode::normalize($mode);
            }

            if ($system->enforce_stock_on_sale !== null) {
                return StockEnforcementMode::fromLegacyBoolean((bool) $system->enforce_stock_on_sale);
            }
        }

        $configMode = config('inventory.stock_enforcement_mode');
        if (is_string($configMode) && $configMode !== '') {
            return StockEnforcementMode::normalize($configMode);
        }

        return StockEnforcementMode::DEFERRED;
    }

    public function enforceStockOnSale(?int $outletId): bool
    {
        return $this->getMode($outletId) === StockEnforcementMode::STRICT;
    }

    public function defersConsumption(?int $outletId): bool
    {
        return $this->getMode($outletId) === StockEnforcementMode::DEFERRED;
    }

    public function warnsOnShortage(?int $outletId): bool
    {
        return $this->getMode($outletId) === StockEnforcementMode::WARNING;
    }

    public function allowsNegativeStock(?int $outletId): bool
    {
        if ($outletId !== null && $outletId > 0) {
            $override = DB::table('outlet_inventory_settings')
                ->where('outlet_id', $outletId)
                ->value('allow_negative_stock');
            if ($override !== null) {
                return (bool) $override;
            }
        }

        $system = SystemSetting::query()->first();
        if ($system !== null && $system->allow_negative_stock !== null) {
            return (bool) $system->allow_negative_stock;
        }

        return (bool) config('inventory.allow_negative_stock', true);
    }
}
