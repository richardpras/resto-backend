<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class DashboardAuditService
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
        string $entityType = 'dashboard_snapshot',
    ): void {
        $this->auditLogService->log(
            $eventType,
            $entityType,
            $entityId,
            $outletId,
            $actor,
            $payload,
        );
    }
}
