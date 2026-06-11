<?php

namespace App\Modules\Production\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'tenantId' => $this->tenant_id !== null ? (int) $this->tenant_id : null,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'type' => (string) $this->type,
            'displayOrder' => (int) $this->display_order,
            'isActive' => (bool) $this->is_active,
            'kdsEnabled' => (bool) $this->kds_enabled,
            'printEnabled' => (bool) $this->print_enabled,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
