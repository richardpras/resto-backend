<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Modules\Inventory\Domain\InventoryStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $outletId = (int) $request->query('outletId', 0);
        $resolvedStock = (float) $this->stock;
        if ($outletId > 0) {
            $row = InventoryStock::query()
                ->where('ingredient_id', $this->id)
                ->where('outlet_id', $outletId)
                ->first();

            $resolvedStock = $row !== null ? (float) $row->stock : (float) $this->stock;
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'stock' => $resolvedStock,
            'min' => (float) $this->min,
            'unit' => $this->unit,
            'price' => $this->price !== null ? (float) $this->price : null,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
