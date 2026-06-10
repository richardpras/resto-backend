<?php

namespace App\Modules\System\Http\Resources;

use App\Models\Modules\System\Domain\FailedJobSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FailedJobSnapshot */
class FailedJobSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'snapshotDate' => $this->snapshot_date?->toDateString(),
            'totalFailures' => (int) $this->total_failures,
            'criticalFailures' => (int) $this->critical_failures,
            'resolvedFailures' => (int) $this->resolved_failures,
            'healthStatus' => (string) $this->health_status,
        ];
    }
}
