<?php

namespace App\Modules\Notifications\Http\Resources;

use App\Models\Modules\Notifications\Domain\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserNotification */
class UserNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'userId' => (int) $this->user_id,
            'severity' => (string) $this->severity,
            'sourceModule' => (string) $this->source_module,
            'sourceType' => (string) $this->source_type,
            'sourceId' => (string) $this->source_id,
            'title' => (string) $this->title,
            'message' => (string) $this->message,
            'actionUrl' => $this->action_url,
            'readAt' => $this->read_at?->toIso8601String(),
            'isRead' => $this->read_at !== null,
            'metadata' => $this->metadata ?? [],
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
