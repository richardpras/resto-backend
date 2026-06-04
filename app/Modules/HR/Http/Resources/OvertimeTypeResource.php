<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\OvertimeType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OvertimeType */
class OvertimeTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => $this->code,
            'name' => $this->name,
            'multiplier' => (float) $this->multiplier,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
