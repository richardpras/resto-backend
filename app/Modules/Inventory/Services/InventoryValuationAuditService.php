<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class InventoryValuationAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public function log(
        string $eventType,
        int $entityId,
        ?int $outletId = null,
        ?User $actor = null,
        ?array $payload = null,
    ): void {
        $this->auditLogService->log(
            $eventType,
            'inventory_valuation',
            $entityId,
            $outletId,
            $actor,
            $payload,
        );
    }
}
