<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\GiftCards\Domain\GiftCardEvent;

class GiftCardEventLogger
{
    /** @param array<string,mixed>|null $payload */
    public function log(int $outletId, ?int $issuanceId, string $eventType, ?string $eventIdempotencyKey = null, ?array $payload = null): GiftCardEvent
    {
        return GiftCardEvent::query()->create([
            'issuance_id' => $issuanceId,
            'outlet_id' => $outletId,
            'event_type' => $eventType,
            'event_idempotency_key' => $eventIdempotencyKey,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
