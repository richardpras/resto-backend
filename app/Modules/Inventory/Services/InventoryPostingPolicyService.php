<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\DeferredConsumptionTrigger;
use App\Modules\Inventory\Support\StockEnforcementMode;
use Illuminate\Support\Facades\DB;

final class InventoryPostingPolicyService
{
    public function __construct(
        private readonly InventorySalePolicyService $salePolicyService,
    ) {}

    public function getDeferredConsumptionTrigger(?int $outletId): string
    {
        if ($outletId !== null && $outletId > 0) {
            $override = DB::table('outlet_inventory_settings')
                ->where('outlet_id', $outletId)
                ->value('deferred_consumption_trigger');
            if ($override !== null && $override !== '') {
                return DeferredConsumptionTrigger::normalize((string) $override);
            }
        }

        $system = SystemSetting::query()->find(1);
        if ($system !== null && is_string($system->deferred_consumption_trigger) && $system->deferred_consumption_trigger !== '') {
            return DeferredConsumptionTrigger::normalize($system->deferred_consumption_trigger);
        }

        $config = config('inventory.deferred_consumption_trigger');
        if (is_string($config) && $config !== '') {
            return DeferredConsumptionTrigger::normalize($config);
        }

        return DeferredConsumptionTrigger::SHIFT_CLOSE;
    }

    public function shouldPostInventoryAtShiftClose(?int $outletId): bool
    {
        if (! $this->salePolicyService->defersConsumption($outletId)) {
            return false;
        }

        return $this->getDeferredConsumptionTrigger($outletId) === DeferredConsumptionTrigger::SHIFT_CLOSE;
    }

    public function shouldPostInventoryAtStocktake(?int $outletId): bool
    {
        if (! $this->salePolicyService->defersConsumption($outletId)) {
            return false;
        }

        return $this->getDeferredConsumptionTrigger($outletId) === DeferredConsumptionTrigger::DAILY_STOCKTAKE;
    }

    public function isDeferredMode(?int $outletId): bool
    {
        return $this->salePolicyService->getMode($outletId) === StockEnforcementMode::DEFERRED;
    }
}
