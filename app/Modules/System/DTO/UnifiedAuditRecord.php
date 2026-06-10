<?php

namespace App\Modules\System\DTO;

final class UnifiedAuditRecord
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $module,
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $action,
        public readonly ?int $userId,
        public readonly ?string $userName,
        public readonly ?int $outletId,
        public readonly string $timestamp,
        public readonly array $before,
        public readonly array $after,
        public readonly array $metadata,
    ) {}
}
