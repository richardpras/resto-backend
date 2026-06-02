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
            'qrPublicId' => $this->qr_public_id,
            'qrEnabled' => (bool) ($this->qr_enabled ?? false),
            'qrVersion' => (int) ($this->qr_version ?? 1),
            'qrLastRotatedAt' => $this->qr_last_rotated_at?->toISOString(),
            'qrUrl' => $this->qr_public_id
                ? rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/').'/qr/'.rawurlencode((string) $this->qr_public_id)
                : null,
            'tableOperationalStatus' => (string) ($this->table_operational_status ?? 'available'),
            'tableOperationalSignals' => $this->table_operational_signals ?? [],
        ];
    }
}
