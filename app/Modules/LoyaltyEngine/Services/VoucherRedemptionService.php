<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;

class VoucherRedemptionService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    public function redeemForPaidOrder(Order $order): ?MemberVoucher
    {
        if ((string) $order->payment_status !== 'paid') {
            return null;
        }

        if ((string) $order->status === 'cancelled') {
            return null;
        }

        return DB::transaction(function () use ($order): ?MemberVoucher {
            $orderVoucher = OrderVoucher::query()
                ->where('order_id', $order->id)
                ->first();

            if ($orderVoucher === null) {
                return null;
            }

            $memberVoucher = MemberVoucher::query()
                ->whereKey($orderVoucher->member_voucher_id)
                ->lockForUpdate()
                ->first();

            if ($memberVoucher === null) {
                return null;
            }

            if ((string) $memberVoucher->status === MemberVoucher::STATUS_REDEEMED) {
                return $memberVoucher;
            }

            if (! in_array((string) $memberVoucher->status, [
                MemberVoucher::STATUS_ISSUED,
                MemberVoucher::STATUS_CLAIMED,
            ], true)) {
                return null;
            }

            $memberVoucher->update([
                'status' => MemberVoucher::STATUS_REDEEMED,
                'redeemed_at' => now(),
            ]);

            $this->auditLogService->log(
                'voucher.redeemed',
                'member_voucher',
                (int) $memberVoucher->id,
                (int) ($order->outlet_id ?? 0),
                null,
                [
                    'orderId' => (int) $order->id,
                    'memberId' => (int) $memberVoucher->member_id,
                    'voucherId' => (int) $memberVoucher->voucher_id,
                    'memberVoucherId' => (int) $memberVoucher->id,
                    'voucherCode' => (string) $memberVoucher->voucher_code,
                    'campaignSource' => $this->resolveCampaignSource($memberVoucher),
                    'discountAmount' => (float) $orderVoucher->discount_amount,
                ],
            );

            $redeemed = $memberVoucher->fresh(['voucher']);
            if ($redeemed !== null) {
                $this->loyaltyNotificationService->dispatchVoucherRedeemed(
                    (int) ($order->outlet_id ?? 0),
                    (int) $memberVoucher->member_id,
                    (string) ($redeemed->voucher?->name ?? $redeemed->voucher_code),
                );

                app(LoyaltyAutomationService::class)->safeProcessEvent(
                    (int) ($order->outlet_id ?? 0),
                    (int) $memberVoucher->member_id,
                    LoyaltyAutomation::TRIGGER_VOUCHER_REDEEMED,
                );
            }

            return $redeemed;
        });
    }

    private function resolveCampaignSource(MemberVoucher $memberVoucher): ?string
    {
        $notes = (string) ($memberVoucher->notes ?? '');
        if ($notes === '') {
            return null;
        }

        if (str_starts_with($notes, MemberVoucher::CAMPAIGN_NOTE_PREFIX)) {
            return $notes;
        }

        return null;
    }
}
