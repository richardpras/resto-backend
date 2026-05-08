<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintReprintAudit;
use App\Models\User;

class PrintReprintAuditRecorder
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function log(
        int $outletId,
        int $renderHistoryId,
        string $action,
        ?User $user,
        ?string $reason = null,
        ?int $printJobId = null,
        ?array $meta = null,
    ): void {
        PrintReprintAudit::query()->create([
            'outlet_id' => $outletId,
            'user_id' => $user?->id,
            'print_job_id' => $printJobId,
            'receipt_render_history_id' => $renderHistoryId,
            'action' => $action,
            'reason' => $reason,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
