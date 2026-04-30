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
                'ingredientId' => (string) $recipe->ingredient_id,
                'qty' => (float) $recipe->qty,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
