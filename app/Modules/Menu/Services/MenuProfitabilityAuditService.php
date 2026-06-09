<?php

namespace App\Modules\Menu\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class MenuProfitabilityAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public function log(
        string $eventType,
        int $menuItemId,
        ?int $outletId = null,
        ?User $actor = null,
        ?array $payload = null,
    ): void {
        $this->auditLogService->log(
            $eventType,
            'menu_item',
            $menuItemId,
            $outletId,
            $actor,
            $payload,
        );
    }
}
