<?php

namespace App\Modules\ShiftClose\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;

class ShiftClosePolicyService
{
    public function openBillPolicy(): string
    {
        $row = SystemSetting::query()->first();
        $policy = $row?->shift_close_open_bill_policy;

        return in_array($policy, ['warn', 'block', 'ignore'], true) ? (string) $policy : 'warn';
    }
}
