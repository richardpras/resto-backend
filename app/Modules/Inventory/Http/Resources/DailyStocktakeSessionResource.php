<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyStocktakeSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'businessDate' => $this->business_date?->toDateString(),
            'status' => (string) $this->status,
            'openingSubmittedAt' => $this->opening_submitted_at?->toIso8601String(),
            'closingSubmittedAt' => $this->closing_submitted_at?->toIso8601String(),
            'postedAt' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,
            'lines' => DailyStocktakeLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
