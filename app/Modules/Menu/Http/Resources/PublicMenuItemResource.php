<?php

namespace App\Modules\Menu\Http\Resources;

use App\Modules\Menu\Services\MenuImageService;
use App\Support\AppLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicMenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $menuImageService = app(MenuImageService::class);
        $locale = AppLocale::fromRequest($request);
        $categoryDisplayName = $this->menuCategory !== null
            ? ($locale === 'id'
                ? ($this->menuCategory->name_id ?: ($this->menuCategory->name_en ?: $this->menuCategory->name))
                : ($this->menuCategory->name_en ?: ($this->menuCategory->name_id ?: $this->menuCategory->name)))
            : ($this->category ?? 'Uncategorized');
        $menuCategoryPayload = null;
        if ($this->menuCategory !== null) {
            $menuCategoryPayload = [
                'id' => (int) $this->menuCategory->id,
                'code' => (string) $this->menuCategory->code,
                'name' => (string) $this->menuCategory->name,
                'nameEn' => $this->menuCategory->name_en !== null ? (string) $this->menuCategory->name_en : null,
                'nameId' => $this->menuCategory->name_id !== null ? (string) $this->menuCategory->name_id : null,
                'displayName' => $categoryDisplayName,
                'sortOrder' => (int) $this->menuCategory->sort_order,
            ];
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'category' => $categoryDisplayName,
            'menuCategory' => $menuCategoryPayload,
            'price' => (float) $this->price,
            'emoji' => $this->emoji,
            'available' => (bool) $this->available,
            'imageUrl' => $this->image_path ? $menuImageService->publicUrl($this->resource) : null,
            'imageVersion' => (int) $this->image_version,
            'hasImage' => $this->image_path !== null,
        ];
    }
}
