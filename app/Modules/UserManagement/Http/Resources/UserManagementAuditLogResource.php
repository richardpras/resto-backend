<?php

namespace App\Modules\UserManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserManagementAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'entityType' => $this->entity_type,
            'entityId' => (int) $this->entity_id,
            'targetUserId' => $this->target_user_id !== null ? (int) $this->target_user_id : null,
            'targetUserName' => $this->whenLoaded('targetUser', fn () => $this->targetUser?->name),
            'actorUserId' => $this->actor_user_id !== null ? (int) $this->actor_user_id : null,
            'actorUserName' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'before' => $this->before_json,
            'after' => $this->after_json,
            'metadata' => $this->metadata,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
