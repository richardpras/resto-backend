<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Read-only catalog item for member profile. */
class LoyaltyRewardCatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'pointsCost' => (int) $this->points_cost,
        ];
    }
}
