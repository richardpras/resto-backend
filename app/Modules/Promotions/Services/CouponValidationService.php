<?php

namespace App\Modules\Promotions\Services;

use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

class CouponValidationService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @param array<string,mixed> $payload */
    public function validate(User $user, array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $couponCode = strtoupper(trim((string) $payload['couponCode']));
        $subtotal = (float) $payload['subtotal'];
        if ($couponCode !== 'WELCOME10') {
            return [
                'valid' => false,
                'reasonCode' => 'coupon_not_found',
                'discountType' => null,
                'discountValue' => 0,
                'discountAmount' => 0,
            ];
        }

        if ($subtotal < 50000) {
            return [
                'valid' => false,
                'reasonCode' => 'min_subtotal_not_met',
                'discountType' => 'percentage',
                'discountValue' => 10,
                'discountAmount' => 0,
            ];
        }

        $discountAmount = round($subtotal * 0.1, 2);

        return [
            'valid' => true,
            'reasonCode' => null,
            'discountType' => 'percentage',
            'discountValue' => 10,
            'discountAmount' => $discountAmount,
        ];
    }
}
