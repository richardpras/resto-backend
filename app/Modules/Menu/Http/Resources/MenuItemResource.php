<?php

namespace App\Modules\Menu\Http\Resources;

use App\Modules\Menu\Services\MenuImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rawOutletId = $request->query('outletId');
        $outletFilterId = is_numeric($rawOutletId) && (int) $rawOutletId >= 1 ? (int) $rawOutletId : null;

        $displayName = $this->name;
        $displayPrice = (float) $this->price;
        if ($outletFilterId !== null && $this->relationLoaded('outletMappings')) {
            foreach ($this->outletMappings as $mapping) {
                if ((int) $mapping->outlet_id !== $outletFilterId || ! $mapping->is_active) {
                    continue;
                }
                if ($mapping->name_override !== null && trim((string) $mapping->name_override) !== '') {
                    $displayName = $mapping->name_override;
                }
                if ($mapping->price_override !== null) {
                    $displayPrice = (float) $mapping->price_override;
                }

                break;
            }
        }

        $menuImageService = app(MenuImageService::class);

        return [
            'id' => (string) $this->id,
            'name' => $displayName,
            'category' => $this->category,
            'emoji' => $this->emoji,
            'imageUrl' => $this->image_path ? $menuImageService->publicUrl($this->resource) : null,
            'imageVersion' => (int) $this->image_version,
            'hasImage' => $this->image_path !== null,
            'price' => $displayPrice,
            'available' => (bool) $this->available,
            'productionStation' => $this->whenLoaded('productionStation', function () {
                if ($this->productionStation === null) {
                    return null;
                }

                return [
                    'id' => (int) $this->productionStation->id,
                    'code' => (string) $this->productionStation->code,
                    'name' => (string) $this->productionStation->name,
                ];
            }),
            'recipes' => $this->whenLoaded('recipes', fn () => $this->recipes->map(fn ($recipe) => [
                'id' => $recipe->id,
                'inventoryItemId' => (string) $recipe->inventory_item_id,
                'quantity' => (float) $recipe->quantity,
            ])),
            'menuItemOutlets' => $this->whenLoaded('outletMappings', fn () => $this->outletMappings->map(fn ($mapping) => [
                'outletId' => (int) $mapping->outlet_id,
                'isActive' => (bool) $mapping->is_active,
                'priceOverride' => $mapping->price_override !== null ? (float) $mapping->price_override : null,
                'nameOverride' => $mapping->name_override,
                'receiptName' => $mapping->receipt_name,
            ])),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
