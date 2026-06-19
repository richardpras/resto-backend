<?php

namespace App\Modules\PromotionEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'config' => $this->config ?? [],
            'conditions' => $this->conditions ?? [],
            'priority' => (int) $this->priority,
            'isCombinable' => (bool) $this->is_combinable,
            'exclusive' => (bool) $this->exclusive,
            'validFrom' => $this->valid_from?->toISOString(),
            'validUntil' => $this->valid_until?->toISOString(),
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
