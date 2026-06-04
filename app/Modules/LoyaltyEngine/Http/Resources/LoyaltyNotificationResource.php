<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (string) $this->outlet_id,
            'memberId' => (string) $this->member_id,
            'eventType' => (string) $this->event_type,
            'channel' => (string) $this->channel,
            'title' => (string) $this->title,
            'content' => (string) $this->content,
            'status' => (string) $this->status,
            'payload' => $this->payload_json ?? [],
            'sentAt' => $this->sent_at?->toIso8601String(),
            'readAt' => $this->read_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
