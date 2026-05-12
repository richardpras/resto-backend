<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemRecoveryEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'orderId' => (int) $this->order_id,
            'orderItemId' => (int) $this->order_item_id,
            'eventCode' => (string) $this->event_code,
            'recoveryStatus' => $this->recovery_status,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'actorUserId' => $this->actor_user_id !== null ? (int) $this->actor_user_id : null,
            'managerUserId' => $this->manager_user_id !== null ? (int) $this->manager_user_id : null,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
