<?php

namespace App\Modules\Menu\Http\Resources;

use App\Modules\Menu\Services\MenuImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicMenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $menuImageService = app(MenuImageService::class);

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'price' => (float) $this->price,
            'emoji' => $this->emoji,
            'available' => (bool) $this->available,
            'imageUrl' => $this->image_path ? $menuImageService->publicUrl($this->resource) : null,
            'imageVersion' => (int) $this->image_version,
            'hasImage' => $this->image_path !== null,
        ];
    }
}
