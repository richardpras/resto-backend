<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use App\Modules\LoyaltyEngine\Services\TierBenefitService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyTierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'qualificationType' => (string) $this->qualification_type,
            'qualificationConfig' => $this->qualificationConfig(),
            'benefitConfig' => resolve(TierBenefitService::class)->normalizeConfig($this->benefitConfig()),
            'sortOrder' => (int) $this->sort_order,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
