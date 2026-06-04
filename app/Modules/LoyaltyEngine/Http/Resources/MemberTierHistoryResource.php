<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberTierHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tierId' => (string) $this->tier_id,
            'tierCode' => (string) ($this->tier?->code ?? ''),
            'tierName' => (string) ($this->tier?->name ?? ''),
            'assignedAt' => $this->assigned_at?->toIso8601String(),
            'removedAt' => $this->removed_at?->toIso8601String(),
            'reason' => (string) $this->reason,
        ];
    }
}
