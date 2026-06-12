<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;

class QrOrderCustomerAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string, mixed>|null $payload */
    public function log(string $eventType, QrOrderRequest $request, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            $eventType,
            'qr_order_request',
            (int) $request->id,
            (int) $request->outlet_id,
            $actor,
            $payload,
        );
    }
}
