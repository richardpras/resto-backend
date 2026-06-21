<?php

namespace App\Modules\Hardware\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HardwareBridgeDeviceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $summary */
        $summary = is_array($this->resource) ? $this->resource : [];

        return $summary;
    }
}
