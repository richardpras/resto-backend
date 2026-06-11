<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Services\TableQrService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Orders\Domain\RestaurantTable */
class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TableQrService $qrService */
        $qrService = app(TableQrService::class);
        $qr = $qrService->buildPayload($this->resource);

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
            'qrUrl' => $qr['qrUrl'],
            'qrStatus' => $qr['qrStatus'],
            'qrStatusReason' => $qr['qrStatusReason'],
            'tableOperationalStatus' => (string) ($this->table_operational_status ?? 'available'),
            'tableOperationalSignals' => $this->table_operational_signals ?? [],
        ];
    }
}
