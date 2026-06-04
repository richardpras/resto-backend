<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoyaltyAutomationExecutor
{
    public function __construct(
        private readonly LoyaltyVoucherService $voucherService,
        private readonly MemberVoucherService $memberVoucherService,
        private readonly LoyaltyNotificationService $notificationService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function execute(LoyaltyAutomation $automation, Member $member, array $context = []): array
    {
        return match ($automation->action_type) {
            LoyaltyAutomation::ACTION_ISSUE_VOUCHER => $this->issueVoucher($automation, $member),
            LoyaltyAutomation::ACTION_SEND_NOTIFICATION => $this->sendNotification($automation, $member, $context),
            LoyaltyAutomation::ACTION_ASSIGN_CAMPAIGN => $this->assignCampaign($automation, $member),
            default => throw ValidationException::withMessages([
                'actionType' => ['Unsupported automation action.'],
            ]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function issueVoucher(LoyaltyAutomation $automation, Member $member): array
    {
        $config = $automation->actionConfig();
        $voucherId = (int) ($config['voucherId'] ?? 0);
        if ($voucherId < 1) {
            throw ValidationException::withMessages([
                'actionConfig.voucherId' => ['Voucher is required for issue_voucher action.'],
            ]);
        }

        $voucher = $this->voucherService->findActiveForIssuance($voucherId, (int) $member->outlet_id);
        $issued = $this->memberVoucherService->issue(
            user: null,
            member: $member,
            voucher: $voucher,
            notes: 'automation:'.$automation->code,
        );

        return [
            'memberVoucherId' => (int) $issued->id,
            'voucherId' => $voucherId,
            'voucherCode' => (string) $issued->voucher_code,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sendNotification(LoyaltyAutomation $automation, Member $member, array $context): array
    {
        $config = $automation->actionConfig();
        $title = trim((string) ($config['title'] ?? $automation->name));
        $content = trim((string) ($config['content'] ?? $automation->description ?? $automation->name));

        if ($title === '') {
            $title = $automation->name;
        }
        if ($content === '') {
            $content = $automation->name;
        }

        $variables = [
            'member_name' => (string) ($member->full_name ?? $member->name ?? 'Member'),
            'points' => (string) ($context['points'] ?? ''),
            'tier_name' => (string) ($context['tierName'] ?? ''),
            'voucher_name' => (string) ($context['voucherName'] ?? ''),
            'reward_name' => (string) ($context['rewardName'] ?? ''),
            'campaign_name' => (string) ($context['campaignName'] ?? ''),
            'current_balance' => (string) ($context['currentBalance'] ?? ''),
        ];

        foreach ($variables as $key => $value) {
            $title = str_replace('{{'.$key.'}}', $value, $title);
            $content = str_replace('{{'.$key.'}}', $value, $content);
        }

        $this->notificationService->dispatchCustomMessage(
            (int) $member->outlet_id,
            (int) $member->id,
            $title,
            $content,
            [
                'automationId' => (int) $automation->id,
                'automationCode' => (string) $automation->code,
            ],
        );

        return [
            'title' => $title,
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignCampaign(LoyaltyAutomation $automation, Member $member): array
    {
        $config = $automation->actionConfig();
        $campaignId = (int) ($config['campaignId'] ?? 0);
        if ($campaignId < 1) {
            throw ValidationException::withMessages([
                'actionConfig.campaignId' => ['Campaign is required for assign_campaign action.'],
            ]);
        }

        $campaign = LoyaltyCampaign::query()->whereKey($campaignId)->first();
        if ($campaign === null || (int) $campaign->outlet_id !== (int) $member->outlet_id) {
            throw ValidationException::withMessages([
                'actionConfig.campaignId' => ['Campaign not found for this outlet.'],
            ]);
        }

        if (! in_array($campaign->status, [
            LoyaltyCampaign::STATUS_ACTIVE,
            LoyaltyCampaign::STATUS_SCHEDULED,
            LoyaltyCampaign::STATUS_DRAFT,
        ], true)) {
            throw ValidationException::withMessages([
                'actionConfig.campaignId' => ['Campaign is not eligible for assignment.'],
            ]);
        }

        $created = LoyaltyCampaignAudience::query()->firstOrCreate(
            [
                'campaign_id' => $campaign->id,
                'member_id' => $member->id,
            ],
            [
                'captured_at' => now(),
            ],
        );

        if ($campaign->status === LoyaltyCampaign::STATUS_ACTIVE) {
            try {
                $this->notificationService->dispatchCampaignActivated(
                    (int) $member->outlet_id,
                    (int) $member->id,
                    (string) $campaign->name,
                );
            } catch (\Throwable $exception) {
                Log::warning('loyalty.automation.campaign_notification_failed', [
                    'campaignId' => $campaign->id,
                    'memberId' => $member->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'campaignId' => (int) $campaign->id,
            'campaignName' => (string) $campaign->name,
            'assigned' => $created->wasRecentlyCreated,
        ];
    }
}
