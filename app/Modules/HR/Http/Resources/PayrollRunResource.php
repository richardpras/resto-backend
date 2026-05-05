<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'period' => $this->period,
            'outlet' => $this->outlet,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toISOString(),
            'paidAt' => $this->paid_at?->toISOString(),
            'lines' => PayrollLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
