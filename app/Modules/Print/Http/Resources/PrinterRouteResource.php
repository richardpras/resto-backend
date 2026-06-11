<?php

namespace App\Modules\Print\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $station = null;
        if ($this->production_station_id !== null) {
            $loaded = $this->relationLoaded('productionStation') ? $this->productionStation : null;
            $station = [
                'id' => (int) $this->production_station_id,
                'code' => (string) ($this->station_code ?? $loaded?->code ?? ''),
                'name' => (string) ($loaded?->name ?? $this->station_code ?? ''),
            ];
        } elseif (is_string($this->station_code) && $this->station_code !== '') {
            $station = [
                'id' => 0,
                'code' => (string) $this->station_code,
                'name' => (string) ($this->station ?? $this->station_code),
            ];
        }

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'printerProfileId' => (int) $this->printer_profile_id,
            'printType' => (string) $this->print_type,
            'routeScope' => (string) ($this->route_scope ?? 'default'),
            'itemId' => $this->item_id !== null ? (int) $this->item_id : null,
            'productionStationId' => $this->production_station_id !== null ? (int) $this->production_station_id : null,
            'stationCode' => $this->station_code,
            'productionStation' => $station,
            'station' => $this->station,
            'category' => $this->category,
            'priority' => (int) $this->priority,
            'isActive' => (bool) $this->is_active,
            'meta' => $this->meta,
        ];
    }
}
