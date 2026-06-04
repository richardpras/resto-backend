<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyAutomationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'automationId' => (string) $this->automation_id,
            'memberId' => (string) $this->member_id,
            'triggerType' => (string) $this->trigger_type,
            'actionType' => (string) $this->action_type,
            'status' => (string) $this->status,
            'result' => $this->result_json ?? [],
            'executedAt' => $this->executed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
