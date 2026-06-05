<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollRunAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollRunAudit */
class PayrollRunAuditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->relationLoaded('performedByUser') ? $this->performedByUser : null;

        return [
            'id' => (int) $this->id,
            'payrollRunId' => (int) $this->payroll_run_id,
            'action' => $this->action,
            'performedBy' => $user ? [
                'id' => (int) $user->id,
                'name' => $user->name,
            ] : null,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
