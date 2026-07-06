<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use Illuminate\Validation\ValidationException;

class ReservationDepositCalculator
{
    public function calculate(OutletReservationSetting $settings, float $orderTotal): float
    {
        if ($settings->deposit_mode === 'percent') {
            $percent = (float) ($settings->deposit_percent ?? 0);
            if ($percent <= 0) {
                throw ValidationException::withMessages([
                    'depositPercent' => ['Deposit percent must be greater than zero when mode is percent.'],
                ]);
            }
            if ($orderTotal <= 0) {
                throw ValidationException::withMessages([
                    'items' => ['Pre-order total must be greater than zero for percent deposit mode.'],
                ]);
            }

            return round($orderTotal * $percent / 100, 2);
        }

        $flat = (float) ($settings->deposit_flat_amount ?? 0);
        if ($flat <= 0) {
            throw ValidationException::withMessages([
                'depositFlatAmount' => ['Deposit flat amount must be greater than zero when mode is flat.'],
            ]);
        }

        return round($flat, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function assertPreorderRules(OutletReservationSetting $settings, array $items): void
    {
        if ($settings->preorder_required && $items === []) {
            throw ValidationException::withMessages([
                'items' => ['Pre-order menu items are required for this outlet.'],
            ]);
        }

        if ($settings->deposit_mode === 'percent' && $items === []) {
            throw ValidationException::withMessages([
                'items' => ['Pre-order menu items are required when deposit mode is percent.'],
            ]);
        }
    }
}
