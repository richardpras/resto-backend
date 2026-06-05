<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => $this->outlet_id !== null ? (string) $this->outlet_id : null,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
