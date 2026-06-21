<?php

namespace App\Modules\Menu\Http\Resources;

use App\Support\AppLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = AppLocale::fromRequest($request);
        $displayName = $locale === 'id'
            ? ($this->name_id ?: ($this->name_en ?: $this->name))
            : ($this->name_en ?: ($this->name_id ?: $this->name));
        $displayDescription = $locale === 'id'
            ? ($this->description_id ?: ($this->description_en ?: $this->description))
            : ($this->description_en ?: ($this->description_id ?: $this->description));

        return [
            'id' => (int) $this->id,
            'tenantId' => $this->tenant_id !== null ? (int) $this->tenant_id : null,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'nameEn' => $this->name_en !== null ? (string) $this->name_en : null,
            'nameId' => $this->name_id !== null ? (string) $this->name_id : null,
            'displayName' => (string) $displayName,
            'description' => $this->description !== null ? (string) $this->description : null,
            'descriptionEn' => $this->description_en !== null ? (string) $this->description_en : null,
            'descriptionId' => $this->description_id !== null ? (string) $this->description_id : null,
            'displayDescription' => $displayDescription !== null ? (string) $displayDescription : null,
            'sortOrder' => (int) $this->sort_order,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
