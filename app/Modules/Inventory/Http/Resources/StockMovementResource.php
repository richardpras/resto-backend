<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ingredient_id' => $this->ingredient_id,
            'ingredient_name' => $this->ingredient?->name,
            'movement_type' => $this->movement_type,
            'quantity' => (float) $this->quantity,
            'source' => $this->source,
            'reference_no' => $this->reference_no,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
