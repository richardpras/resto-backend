<?php

namespace App\Modules\Print\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'type' => (string) $this->type,
            'status' => (string) $this->status,
            'attempts' => (int) $this->attempts,
            'retryable' => (bool) $this->retryable,
            'nextRetryAt' => $this->next_retry_at?->toIso8601String(),
            'lastError' => $this->last_error,
            'recoveryState' => (string) $this->recovery_state,
        ];
    }
}
