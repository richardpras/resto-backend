<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyAutomationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'triggerType' => (string) $this->trigger_type,
            'condition' => $this->conditionConfig(),
            'actionType' => (string) $this->action_type,
            'actionConfig' => $this->actionConfig(),
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
