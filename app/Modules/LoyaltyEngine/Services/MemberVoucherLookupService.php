<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Validation\ValidationException;

class MemberVoucherLookupService
{
    public function findRedeemableByCode(int $outletId, string $code): MemberVoucher
    {
        $normalized = trim($code);
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'code' => ['Voucher code is required.'],
            ]);
        }

        $memberVoucher = MemberVoucher::query()
            ->with('voucher')
            ->where('voucher_code', $normalized)
            ->first();

        if ($memberVoucher === null) {
            throw ValidationException::withMessages([
                'code' => ['Voucher code not found.'],
            ]);
        }

        if ((int) $memberVoucher->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'code' => ['Voucher is not valid for this outlet.'],
            ]);
        }

        $status = (string) $memberVoucher->status;
        if (! in_array($status, [MemberVoucher::STATUS_ISSUED, MemberVoucher::STATUS_CLAIMED], true)) {
            $message = match ($status) {
                MemberVoucher::STATUS_REDEEMED => 'Voucher has already been redeemed.',
                MemberVoucher::STATUS_EXPIRED => 'Voucher has expired.',
                MemberVoucher::STATUS_CANCELLED => 'Voucher has been cancelled.',
                default => 'Voucher is not available for use.',
            };

            throw ValidationException::withMessages([
                'code' => [$message],
            ]);
        }

        return $memberVoucher;
    }
}
