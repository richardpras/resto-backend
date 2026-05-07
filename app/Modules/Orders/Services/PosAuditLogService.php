<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\User;

class PosAuditLogService
{
    /** @param array<string,mixed>|null $payload */
    public function log(
        string $eventType,
        string $entityType,
        int $entityId,
        ?int $outletId = null,
        ?User $actor = null,
        ?array $payload = null
    ): void {
        PosEventLog::query()->create([
            'outlet_id' => $outletId,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
