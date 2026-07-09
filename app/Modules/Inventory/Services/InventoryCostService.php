<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Purchase\Domain\InventoryProcurementSetting;
final class InventoryCostService
{
    public function __construct(
        private readonly InventoryValuationService $valuationService,
        private readonly InventoryCostingPolicyService $costingPolicyService,
    ) {}

    public function resolveUnitCost(int $ingredientId, int $outletId): float
    {
        $unitCost = $this->valuationService->getAverageCost($ingredientId, $outletId);
        if ($unitCost > 0) {
            return $unitCost;
        }

        $valuation = $this->valuationService->getInventoryValue($ingredientId, $outletId);
        if ($valuation['lastPurchaseCost'] > 0) {
            return $valuation['lastPurchaseCost'];
        }

        $procurementLast = InventoryProcurementSetting::query()
            ->where('inventory_item_id', $ingredientId)
            ->where('is_active', true)
            ->value('last_purchase_price');
        if ($procurementLast !== null && (float) $procurementLast > 0) {
            return (float) $procurementLast;
        }

        $masterPrice = Ingredient::query()->whereKey($ingredientId)->value('price');

        return (float) ($masterPrice ?? 0);
    }

    public function calculateRawMenuItemCost(int $menuItemId, int $outletId): float
    {
        $recipes = \Illuminate\Support\Facades\DB::table('menu_recipes')
            ->where('menu_item_id', $menuItemId)
            ->get(['inventory_item_id', 'quantity']);

        $total = 0.0;
        foreach ($recipes as $recipe) {
            $unitCost = $this->resolveUnitCost((int) $recipe->inventory_item_id, $outletId);
            $total += $unitCost * (float) $recipe->quantity;
        }

        return round($total, 4);
    }

    public function getCostingMethod(): string
    {
        return $this->costingPolicyService->getMethod();
    }
}
