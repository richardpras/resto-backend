<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollRunV2;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollRunV2 */
class PayrollRunV2Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $period = $this->relationLoaded('preparationPeriod') ? $this->preparationPeriod : null;

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'payrollPreparationPeriodId' => (int) $this->payroll_preparation_period_id,
            'status' => $this->status,
            'paymentStatus' => $this->payment_status,
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'finalizedAt' => $this->finalized_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'closedAt' => $this->closed_at?->toIso8601String(),
            'closedNotes' => $this->closed_notes,
            'isClosed' => $this->status === PayrollRunV2::STATUS_CLOSED,
            'itemCount' => $this->when(
                isset($this->item_count),
                fn () => (int) $this->item_count,
            ),
            'preparationPeriod' => $period ? [
                'id' => (int) $period->id,
                'periodStart' => $period->period_start?->toDateString(),
                'periodEnd' => $period->period_end?->toDateString(),
                'status' => $period->status,
            ] : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
