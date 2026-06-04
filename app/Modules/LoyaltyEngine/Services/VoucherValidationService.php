<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\Order;
use Illuminate\Validation\ValidationException;

class VoucherValidationService
{
    public function __construct(
        private readonly LoyaltyVoucherService $voucherService,
    ) {}

    public function validateForOrder(Order $order, MemberVoucher $memberVoucher): LoyaltyVoucher
    {
        if ($order->member_id === null || (int) $order->member_id < 1) {
            throw ValidationException::withMessages([
                'memberId' => ['Order must have a member before a voucher can be applied.'],
            ]);
        }

        if ((int) $memberVoucher->member_id !== (int) $order->member_id) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Voucher must belong to the member attached to this order.'],
            ]);
        }

        $this->validateStatus($memberVoucher);
        $this->validateOutlet($order, $memberVoucher);

        $voucher = $memberVoucher->voucher ?? LoyaltyVoucher::query()->find($memberVoucher->voucher_id);
        if ($voucher === null) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Voucher definition not found.'],
            ]);
        }

        $this->validateActiveVoucher($voucher);
        $this->voucherService->validateVoucherWindow($voucher);
        $this->validateValueType($voucher);
        $this->validateMinimumSpend($voucher, (float) $order->subtotal);

        return $voucher;
    }

    private function validateStatus(MemberVoucher $memberVoucher): void
    {
        $status = (string) $memberVoucher->status;

        if (! in_array($status, [MemberVoucher::STATUS_ISSUED, MemberVoucher::STATUS_CLAIMED], true)) {
            $message = match ($status) {
                MemberVoucher::STATUS_REDEEMED => 'Voucher has already been redeemed.',
                MemberVoucher::STATUS_EXPIRED => 'Voucher has expired.',
                MemberVoucher::STATUS_CANCELLED => 'Voucher has been cancelled.',
                default => 'Voucher is not available for use.',
            };

            throw ValidationException::withMessages([
                'memberVoucherId' => [$message],
            ]);
        }
    }

    private function validateOutlet(Order $order, MemberVoucher $memberVoucher): void
    {
        if ((int) $order->outlet_id !== (int) $memberVoucher->outlet_id) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Voucher is not valid for this outlet.'],
            ]);
        }
    }

    private function validateActiveVoucher(LoyaltyVoucher $voucher): void
    {
        if (! (bool) $voucher->is_active) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Voucher is inactive.'],
            ]);
        }
    }

    private function validateValueType(LoyaltyVoucher $voucher): void
    {
        if ($voucher->value_type === LoyaltyVoucher::VALUE_FREE_ITEM) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Free item vouchers are not supported yet.'],
            ]);
        }

        if (! in_array($voucher->value_type, [
            LoyaltyVoucher::VALUE_PERCENTAGE,
            LoyaltyVoucher::VALUE_FIXED_AMOUNT,
        ], true)) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Unsupported voucher discount type.'],
            ]);
        }
    }

    private function validateMinimumSpend(LoyaltyVoucher $voucher, float $subtotal): void
    {
        $minimum = (float) ($voucher->minimum_spend ?? 0);
        if ($minimum > 0 && $subtotal < $minimum) {
            throw ValidationException::withMessages([
                'memberVoucherId' => ['Order subtotal does not meet the voucher minimum spend.'],
            ]);
        }
    }
}
