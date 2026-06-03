<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyProgramRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'loyaltyProgramId' => (string) $this->loyalty_program_id,
            'ruleType' => (string) $this->rule_type,
            'config' => $this->config ?? [],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
