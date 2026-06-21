<?php

namespace App\Modules\Menu\Http\Resources;

use App\Support\AppLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryPrinterMappingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = AppLocale::fromRequest($request);
        $displayCategoryName = null;
        if ($this->relationLoaded('category') && $this->category !== null) {
            $displayCategoryName = $locale === 'id'
                ? ($this->category->name_id ?: ($this->category->name_en ?: $this->category->name))
                : ($this->category->name_en ?: ($this->category->name_id ?: $this->category->name));
        }

        return [
            'id' => (int) $this->id,
            'tenantId' => $this->tenant_id !== null ? (int) $this->tenant_id : null,
            'outletId' => (int) $this->outlet_id,
            'menuCategoryId' => (int) $this->menu_category_id,
            'printerProfileId' => (int) $this->printer_profile_id,
            'priority' => (int) $this->priority,
            'isActive' => (bool) $this->is_active,
            'meta' => $this->meta,
            'menuCategory' => $this->whenLoaded('category', function () use ($displayCategoryName) {
                if ($this->category === null) {
                    return null;
                }

                return [
                    'id' => (int) $this->category->id,
                    'code' => (string) $this->category->code,
                    'name' => (string) $this->category->name,
                    'nameEn' => $this->category->name_en !== null ? (string) $this->category->name_en : null,
                    'nameId' => $this->category->name_id !== null ? (string) $this->category->name_id : null,
                    'displayName' => $displayCategoryName,
                ];
            }),
            'printerProfile' => $this->whenLoaded('printerProfile', function () {
                if ($this->printerProfile === null) {
                    return null;
                }

                return [
                    'id' => (int) $this->printerProfile->id,
                    'name' => (string) $this->printerProfile->name,
                    'code' => (string) $this->printerProfile->code,
                    'connectionType' => (string) $this->printerProfile->connection_type,
                    'station' => $this->printerProfile->station,
                ];
            }),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
