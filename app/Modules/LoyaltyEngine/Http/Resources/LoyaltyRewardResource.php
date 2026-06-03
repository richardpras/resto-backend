<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyRewardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'pointsCost' => (int) $this->points_cost,
            'isActive' => (bool) $this->is_active,
            'sortOrder' => $this->sort_order !== null ? (int) $this->sort_order : null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
