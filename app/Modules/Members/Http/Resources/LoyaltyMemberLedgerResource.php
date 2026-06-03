<?php

namespace App\Modules\Members\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyMemberLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'program' => $this->relationLoaded('program') && $this->program !== null
                ? (string) $this->program->name
                : null,
            'programCode' => $this->relationLoaded('program') && $this->program !== null
                ? (string) $this->program->code
                : null,
            'type' => (string) $this->type,
            'points' => (int) $this->points,
            'referenceType' => $this->reference_type,
            'referenceId' => $this->reference_id,
            'description' => $this->description,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
