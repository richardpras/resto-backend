<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'stock' => (float) $this->stock,
            'min' => (float) $this->min,
            'unit' => $this->unit,
            'price' => $this->price !== null ? (float) $this->price : null,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
