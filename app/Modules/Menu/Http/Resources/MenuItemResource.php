<?php

namespace App\Modules\Menu\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'emoji' => $this->emoji,
            'price' => (float) $this->price,
            'available' => (bool) $this->available,
            'recipes' => $this->whenLoaded('recipes', fn () => $this->recipes->map(fn ($recipe) => [
                'id' => $recipe->id,
                'inventoryItemId' => (string) $recipe->inventory_item_id,
                'quantity' => (float) $recipe->quantity,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
