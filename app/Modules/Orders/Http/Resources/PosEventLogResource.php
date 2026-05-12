<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosEventLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'actorUserId' => $this->actor_user_id !== null ? (int) $this->actor_user_id : null,
            'eventType' => (string) $this->event_type,
            'entityType' => (string) $this->entity_type,
            'entityId' => (int) $this->entity_id,
            'payload' => $this->payload,
            'occurredAt' => $this->occurred_at?->toISOString(),
        ];
    }
}
