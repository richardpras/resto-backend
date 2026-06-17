<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;

class QrOrderingSettingsService
{
    public function enableCallCashier(): bool
    {
        $row = SystemSetting::query()->first();

        return $row === null
            ? (bool) config('qr_ordering.enable_call_cashier', true)
            : (bool) ($row->enable_call_cashier ?? true);
    }

    public function enableQrOrdering(): bool
    {
        $row = SystemSetting::query()->first();

        return $row === null
            ? (bool) config('qr_ordering.enable_qr_ordering', true)
            : (bool) ($row->enable_qr_ordering ?? true);
    }

    public function pendingConfirmationTtlMinutes(): int
    {
        $row = SystemSetting::query()->first();
        $value = $row?->qr_pending_confirmation_ttl_minutes;

        if ($value === null) {
            return (int) config('qr_ordering.pending_confirmation_ttl_minutes', 20);
        }

        return max(5, min(120, (int) $value));
    }

    public function requireCustomerApprovalForAdjustments(): bool
    {
        $row = SystemSetting::query()->first();

        return $row !== null && (bool) ($row->require_customer_approval_for_adjustments ?? false);
    }

    /** @return array{enableCallCashier: bool, requireCustomerApprovalForAdjustments: bool, pendingConfirmationTtlMinutes: int} */
    public function publicQrOrderingConfig(): array
    {
        return [
            'enableCallCashier' => $this->enableCallCashier(),
            'requireCustomerApprovalForAdjustments' => $this->requireCustomerApprovalForAdjustments(),
            'pendingConfirmationTtlMinutes' => $this->pendingConfirmationTtlMinutes(),
        ];
    }
}
