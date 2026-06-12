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

    public function requireCustomerApprovalForAdjustments(): bool
    {
        $row = SystemSetting::query()->first();

        return $row !== null && (bool) ($row->require_customer_approval_for_adjustments ?? false);
    }

    /** @return array{enableCallCashier: bool, requireCustomerApprovalForAdjustments: bool} */
    public function publicQrOrderingConfig(): array
    {
        return [
            'enableCallCashier' => $this->enableCallCashier(),
            'requireCustomerApprovalForAdjustments' => $this->requireCustomerApprovalForAdjustments(),
        ];
    }
}
