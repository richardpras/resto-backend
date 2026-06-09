<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryValuationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ingredientId' => (string) $this->ingredient_id,
            'ingredientName' => $this->whenLoaded('ingredient', fn () => $this->ingredient?->name),
            'ingredientUnit' => $this->whenLoaded('ingredient', fn () => $this->ingredient?->unit),
            'outletId' => (int) $this->outlet_id,
            'stockQuantity' => (float) $this->stock_quantity,
            'inventoryValue' => round((float) $this->inventory_value, 4),
            'averageCost' => (float) $this->average_cost,
            'lastPurchaseCost' => (float) $this->last_purchase_cost,
            'lastGrnId' => $this->last_grn_id !== null ? (int) $this->last_grn_id : null,
            'lastUpdatedAt' => $this->last_updated_at?->toIso8601String(),
        ];
    }
}
