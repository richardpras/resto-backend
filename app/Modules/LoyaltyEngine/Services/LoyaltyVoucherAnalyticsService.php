<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyVoucherAnalyticsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, int|float|list<array<string, int|float|string>>>
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $memberVoucherQuery = MemberVoucher::query()->where('outlet_id', $outletId);
        $orderVoucherQuery = OrderVoucher::query()
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId));

        $redeemedOrderVoucherQuery = OrderVoucher::query()
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId))
            ->whereHas('memberVoucher', fn ($q) => $q->where('status', MemberVoucher::STATUS_REDEEMED));

        $topRedeemedVouchers = OrderVoucher::query()
            ->select([
                'voucher_id',
                DB::raw('COUNT(*) as redemptions'),
                DB::raw('SUM(discount_amount) as redemption_value'),
            ])
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId))
            ->whereHas('memberVoucher', fn ($q) => $q->where('status', MemberVoucher::STATUS_REDEEMED))
            ->groupBy('voucher_id')
            ->orderByDesc('redemptions')
            ->limit(5)
            ->get()
            ->map(function ($row): array {
                $voucher = LoyaltyVoucher::query()->find($row->voucher_id);

                return [
                    'voucherId' => (int) $row->voucher_id,
                    'voucherCode' => $voucher?->code ?? '',
                    'voucherName' => $voucher?->name ?? '',
                    'redemptions' => (int) $row->redemptions,
                    'redemptionValue' => (float) $row->redemption_value,
                ];
            })
            ->values()
            ->all();

        $topVouchersUsed = OrderVoucher::query()
            ->select([
                'voucher_id',
                DB::raw('COUNT(*) as applications'),
                DB::raw('SUM(discount_amount) as preview_amount'),
            ])
            ->whereHas('order', fn ($q) => $q->where('outlet_id', $outletId))
            ->groupBy('voucher_id')
            ->orderByDesc('applications')
            ->limit(5)
            ->get()
            ->map(function ($row): array {
                $voucher = LoyaltyVoucher::query()->find($row->voucher_id);

                return [
                    'voucherId' => (int) $row->voucher_id,
                    'voucherCode' => $voucher?->code ?? '',
                    'voucherName' => $voucher?->name ?? '',
                    'applications' => (int) $row->applications,
                    'previewAmount' => (float) $row->preview_amount,
                ];
            })
            ->values()
            ->all();

        return [
            'vouchersCount' => (int) LoyaltyVoucher::query()->where('outlet_id', $outletId)->count(),
            'issuedVouchers' => (int) (clone $memberVoucherQuery)->where('status', MemberVoucher::STATUS_ISSUED)->count(),
            'claimedVouchers' => (int) (clone $memberVoucherQuery)->where('status', MemberVoucher::STATUS_CLAIMED)->count(),
            'redeemedVouchers' => (int) (clone $memberVoucherQuery)->where('status', MemberVoucher::STATUS_REDEEMED)->count(),
            'expiredVouchers' => (int) (clone $memberVoucherQuery)->where('status', MemberVoucher::STATUS_EXPIRED)->count(),
            'campaignVoucherIssuanceCount' => (int) (clone $memberVoucherQuery)
                ->where('notes', 'like', MemberVoucher::CAMPAIGN_NOTE_PREFIX.'%')
                ->count(),
            'voucherApplications' => (int) (clone $orderVoucherQuery)->count(),
            'voucherPreviewAmount' => (float) (clone $orderVoucherQuery)->sum('discount_amount'),
            'topVouchersUsed' => $topVouchersUsed,
            'voucherRedemptionCount' => (int) (clone $redeemedOrderVoucherQuery)->count(),
            'voucherRedemptionValue' => (float) (clone $redeemedOrderVoucherQuery)->sum('discount_amount'),
            'topRedeemedVouchers' => $topRedeemedVouchers,
        ];
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}
