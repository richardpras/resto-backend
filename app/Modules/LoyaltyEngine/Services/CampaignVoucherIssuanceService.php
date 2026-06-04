<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CampaignVoucherIssuanceService
{
    public function __construct(
        private readonly LoyaltyVoucherService $voucherService,
        private readonly MemberVoucherService $memberVoucherService,
        private readonly LoyaltyCampaignExecutionService $campaignExecutionService,
    ) {}

    /**
     * @return array{
     *     campaign: LoyaltyCampaign,
     *     voucherId: int,
     *     audienceCount: int,
     *     issuedCount: int,
     *     skippedCount: int
     * }
     */
    public function issueToCampaignAudience(?User $user, LoyaltyCampaign $campaign, int $voucherId): array
    {
        if (! in_array($campaign->status, [
            LoyaltyCampaign::STATUS_ACTIVE,
            LoyaltyCampaign::STATUS_COMPLETED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Campaign must be active or completed with a captured audience.'],
            ]);
        }

        $capturedCount = $this->campaignExecutionService->countCapturedAudience($campaign);
        if ($capturedCount < 1) {
            throw ValidationException::withMessages([
                'campaign' => ['Campaign has no captured audience snapshot. Activate the campaign first.'],
            ]);
        }

        $voucher = $this->voucherService->findActiveForIssuance($voucherId, (int) $campaign->outlet_id);

        $memberIds = LoyaltyCampaignAudience::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $issuedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($user, $campaign, $voucher, $memberIds, &$issuedCount, &$skippedCount): void {
            foreach ($memberIds as $memberId) {
                if ($this->memberVoucherService->campaignIssuanceExists((int) $campaign->id, (int) $voucher->id, $memberId)) {
                    $skippedCount++;

                    continue;
                }

                $member = Member::query()->whereKey($memberId)->first();
                if ($member === null) {
                    $skippedCount++;

                    continue;
                }

                $this->memberVoucherService->issue(
                    $user,
                    $member,
                    $voucher,
                    MemberVoucher::campaignNote((int) $campaign->id),
                );
                $issuedCount++;
            }
        });

        return [
            'campaign' => $campaign->fresh(['segment']) ?? $campaign,
            'voucherId' => (int) $voucher->id,
            'audienceCount' => count($memberIds),
            'issuedCount' => $issuedCount,
            'skippedCount' => $skippedCount,
        ];
    }

    public function countIssuedForCampaign(int $campaignId): int
    {
        return $this->memberVoucherService->countCampaignIssuance($campaignId);
    }
}
