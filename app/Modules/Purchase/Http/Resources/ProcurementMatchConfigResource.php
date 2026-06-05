<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementMatchConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (string) $this->outlet_id,
            'quantityTolerancePercent' => (float) $this->quantity_tolerance_percent,
            'priceTolerancePercent' => (float) $this->price_tolerance_percent,
            'amountTolerancePercent' => (float) $this->amount_tolerance_percent,
            'autoApproveWithinTolerance' => (bool) $this->auto_approve_within_tolerance,
            'isActive' => (bool) $this->is_active,
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
