<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Orders\Domain\RestaurantTable */
class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => $this->code,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'zone' => $this->zone,
            'status' => $this->status,
            'active' => (bool) ($this->active ?? true),
        ];
    }
}
