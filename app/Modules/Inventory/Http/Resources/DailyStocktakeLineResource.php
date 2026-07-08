<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyStocktakeLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'ingredientId' => (string) $this->ingredient_id,
            'ingredientName' => $this->whenLoaded('ingredient', fn () => $this->ingredient?->name),
            'ingredientUnit' => $this->whenLoaded('ingredient', fn () => $this->ingredient?->unit),
            'previousClosingQty' => (float) $this->previous_closing_qty,
            'openingQty' => $this->opening_qty !== null ? (float) $this->opening_qty : null,
            'closingQty' => $this->closing_qty !== null ? (float) $this->closing_qty : null,
            'purchasesQty' => (float) $this->purchases_qty,
            'theoreticalUsageQty' => (float) $this->theoretical_usage_qty,
            'overnightVarianceQty' => (float) $this->overnight_variance_qty,
            'operationalVarianceQty' => (float) $this->operational_variance_qty,
            'unitCost' => (float) $this->unit_cost,
        ];
    }
}
