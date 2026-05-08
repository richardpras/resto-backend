<?php

namespace App\Modules\Print\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'printerProfileId' => (int) $this->printer_profile_id,
            'printType' => (string) $this->print_type,
            'routeScope' => (string) ($this->route_scope ?? 'default'),
            'itemId' => $this->item_id !== null ? (int) $this->item_id : null,
            'station' => $this->station,
            'category' => $this->category,
            'priority' => (int) $this->priority,
            'isActive' => (bool) $this->is_active,
            'meta' => $this->meta,
        ];
    }
}
