<?php

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class AccountingAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public function log(string $event, string $entityType, int $entityId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log($event, $entityType, $entityId, $outletId, $actor, $payload);
    }
}
