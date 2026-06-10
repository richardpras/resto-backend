<?php

namespace App\Modules\System\Http\Resources;

use App\Modules\System\DTO\UnifiedAuditRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnifiedAuditRecord */
final class UnifiedAuditRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UnifiedAuditRecord $record */
        $record = $this->resource;

        return [
            'id' => $record->id,
            'module' => $record->module,
            'entityType' => $record->entityType,
            'entityId' => $record->entityId,
            'action' => $record->action,
            'userId' => $record->userId,
            'userName' => $record->userName,
            'outletId' => $record->outletId,
            'timestamp' => $record->timestamp,
            'before' => $record->before,
            'after' => $record->after,
            'metadata' => $record->metadata,
        ];
    }
}
