<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'type' => (string) $this->type,
            'isActive' => (bool) $this->is_active,
            'expiryEnabled' => (bool) $this->expiry_enabled,
            'expiryDays' => $this->expiry_days !== null ? (int) $this->expiry_days : null,
            'effectiveFrom' => $this->effective_from?->format('Y-m-d'),
            'effectiveUntil' => $this->effective_until?->format('Y-m-d'),
            'rulesCount' => (int) ($this->rules_count ?? $this->rules()->count()),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
