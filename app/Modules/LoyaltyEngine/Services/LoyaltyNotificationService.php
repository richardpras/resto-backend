<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotificationTemplate;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LoyaltyNotificationService
{
    public function __construct(
        private readonly LoyaltyBalanceProjectionService $balanceProjectionService,
        private readonly LoyaltyEmailNotificationService $emailNotificationService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        int $outletId,
        int $memberId,
        string $eventType,
        array $variables = [],
        array $payload = [],
    ): void {
        try {
            if ($outletId < 1 || $memberId < 1) {
                return;
            }

            $member = Member::query()->find($memberId);
            if ($member === null) {
                return;
            }

            $context = $this->buildVariableContext($member, $variables);
            $this->createInAppNotification($outletId, $memberId, $eventType, $context, $payload);
            $this->maybeCreateEmailNotification($outletId, $member, $eventType, $context, $payload);
        } catch (\Throwable $exception) {
            Log::warning('loyalty.notification.dispatch_failed', [
                'outletId' => $outletId,
                'memberId' => $memberId,
                'eventType' => $eventType,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function dispatchPointsEarned(int $outletId, int $memberId, int $points): void
    {
        $balance = $this->balanceProjectionService->currentPointsForMember($memberId);
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_POINT_EARNED, [
            'points' => $points,
            'current_balance' => $balance,
        ], ['points' => $points]);
    }

    public function dispatchPointsRedeemed(int $outletId, int $memberId, int $points): void
    {
        $balance = $this->balanceProjectionService->currentPointsForMember($memberId);
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_POINT_REDEEMED, [
            'points' => $points,
            'current_balance' => $balance,
        ], ['points' => $points]);
    }

    public function dispatchPointsExpired(int $outletId, int $memberId, int $points): void
    {
        $balance = $this->balanceProjectionService->currentPointsForMember($memberId);
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_POINT_EXPIRED, [
            'points' => $points,
            'current_balance' => $balance,
        ], ['points' => $points]);
    }

    public function dispatchRewardRedeemed(int $outletId, int $memberId, string $rewardName, int $points): void
    {
        $balance = $this->balanceProjectionService->currentPointsForMember($memberId);
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_REWARD_REDEEMED, [
            'reward_name' => $rewardName,
            'points' => $points,
            'current_balance' => $balance,
        ], ['rewardName' => $rewardName, 'points' => $points]);
    }

    public function dispatchVoucherIssued(int $outletId, int $memberId, string $voucherName): void
    {
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_VOUCHER_ISSUED, [
            'voucher_name' => $voucherName,
        ], ['voucherName' => $voucherName]);
    }

    public function dispatchVoucherRedeemed(int $outletId, int $memberId, string $voucherName): void
    {
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_VOUCHER_REDEEMED, [
            'voucher_name' => $voucherName,
        ], ['voucherName' => $voucherName]);
    }

    public function dispatchTierUpgraded(int $outletId, int $memberId, LoyaltyTier $tier): void
    {
        $balance = $this->balanceProjectionService->currentPointsForMember($memberId);
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_TIER_UPGRADED, [
            'tier_name' => (string) $tier->name,
            'current_balance' => $balance,
        ], ['tierId' => (int) $tier->id, 'tierCode' => (string) $tier->code]);
    }

    public function dispatchCampaignActivated(int $outletId, int $memberId, string $campaignName): void
    {
        $this->dispatch($outletId, $memberId, LoyaltyNotification::EVENT_CAMPAIGN_ACTIVATED, [
            'campaign_name' => $campaignName,
        ], ['campaignName' => $campaignName]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchCustomMessage(
        int $outletId,
        int $memberId,
        string $title,
        string $content,
        array $payload = [],
    ): void {
        try {
            if ($outletId < 1 || $memberId < 1) {
                return;
            }

            LoyaltyNotification::query()->create([
                'outlet_id' => $outletId,
                'member_id' => $memberId,
                'event_type' => 'AUTOMATION',
                'channel' => LoyaltyNotification::CHANNEL_IN_APP,
                'title' => $title,
                'content' => $content,
                'status' => LoyaltyNotification::STATUS_SENT,
                'payload_json' => $payload,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('loyalty.notification.custom_failed', [
                'outletId' => $outletId,
                'memberId' => $memberId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, LoyaltyNotification>
     */
    public function listForMember(?User $user, int $memberId, int $outletId, int $limit = 50, ?string $channel = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $member = Member::query()
            ->whereKey($memberId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found for this outlet.'],
            ]);
        }

        return LoyaltyNotification::query()
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function markRead(?User $user, int $notificationId): LoyaltyNotification
    {
        $notification = LoyaltyNotification::query()->whereKey($notificationId)->first();
        if ($notification === null) {
            throw ValidationException::withMessages([
                'notificationId' => ['Notification not found.'],
            ]);
        }

        $this->assertOutletAllowed($user, (int) $notification->outlet_id);

        if ($notification->channel !== LoyaltyNotification::CHANNEL_IN_APP) {
            throw ValidationException::withMessages([
                'notificationId' => ['Only in-app notifications can be marked as read.'],
            ]);
        }

        if ($notification->read_at === null) {
            $notification->update([
                'status' => LoyaltyNotification::STATUS_READ,
                'read_at' => now(),
            ]);
        }

        return $notification->fresh() ?? $notification;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $payload
     */
    private function createInAppNotification(
        int $outletId,
        int $memberId,
        string $eventType,
        array $variables,
        array $payload,
    ): LoyaltyNotification {
        $template = $this->resolveTemplate($outletId, $eventType, LoyaltyNotification::CHANNEL_IN_APP);
        $title = $this->renderText(
            (string) ($template?->subject ?? $this->defaultSubject($eventType)),
            $variables,
        );
        $content = $this->renderText(
            (string) ($template?->content ?? $this->defaultContent($eventType)),
            $variables,
        );

        return LoyaltyNotification::query()->create([
            'outlet_id' => $outletId,
            'member_id' => $memberId,
            'event_type' => $eventType,
            'channel' => LoyaltyNotification::CHANNEL_IN_APP,
            'title' => $title,
            'content' => $content,
            'status' => LoyaltyNotification::STATUS_SENT,
            'payload_json' => $payload,
            'sent_at' => now(),
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @param  array<string, mixed>  $payload
     */
    private function maybeCreateEmailNotification(
        int $outletId,
        Member $member,
        string $eventType,
        array $variables,
        array $payload,
    ): void {
        if (! $this->emailNotificationService->isConfiguredForOutlet($outletId)) {
            return;
        }

        $email = trim((string) ($member->email ?? ''));
        if ($email === '') {
            return;
        }

        $template = $this->resolveTemplate($outletId, $eventType, LoyaltyNotification::CHANNEL_EMAIL);
        if ($template !== null && ! $template->is_active) {
            return;
        }

        $subject = $this->renderText(
            (string) ($template?->subject ?? $this->defaultSubject($eventType)),
            $variables,
        );
        $content = $this->renderText(
            (string) ($template?->content ?? $this->defaultContent($eventType)),
            $variables,
        );

        $notification = LoyaltyNotification::query()->create([
            'outlet_id' => $outletId,
            'member_id' => (int) $member->id,
            'event_type' => $eventType,
            'channel' => LoyaltyNotification::CHANNEL_EMAIL,
            'title' => $subject,
            'content' => $content,
            'status' => LoyaltyNotification::STATUS_PENDING,
            'payload_json' => $payload,
        ]);

        $sent = $this->emailNotificationService->send(
            outletId: $outletId,
            recipientEmail: $email,
            recipientName: (string) ($member->full_name ?? $member->name ?? 'Member'),
            subject: $subject,
            htmlContent: nl2br(e($content)),
        );

        $notification->update([
            'status' => $sent ? LoyaltyNotification::STATUS_SENT : LoyaltyNotification::STATUS_FAILED,
            'sent_at' => $sent ? now() : null,
        ]);
    }

    private function resolveTemplate(int $outletId, string $eventType, string $channel): ?LoyaltyNotificationTemplate
    {
        return LoyaltyNotificationTemplate::query()
            ->where('outlet_id', $outletId)
            ->where('event_type', $eventType)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, scalar|null>  $variables
     * @return array<string, scalar|null>
     */
    private function buildVariableContext(Member $member, array $variables): array
    {
        return array_merge([
            'member_name' => (string) ($member->full_name ?? $member->name ?? 'Member'),
            'points' => '',
            'tier_name' => '',
            'voucher_name' => '',
            'reward_name' => '',
            'campaign_name' => '',
            'current_balance' => (string) $this->balanceProjectionService->currentPointsForMember((int) $member->id),
        ], $variables);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function renderText(string $text, array $variables): string
    {
        $rendered = $text;
        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', (string) ($value ?? ''), $rendered);
        }

        return $rendered;
    }

    private function defaultSubject(string $eventType): string
    {
        return match ($eventType) {
            LoyaltyNotification::EVENT_POINT_EARNED => 'You earned {{points}} points',
            LoyaltyNotification::EVENT_POINT_REDEEMED => 'You redeemed {{points}} points',
            LoyaltyNotification::EVENT_POINT_EXPIRED => '{{points}} points expired',
            LoyaltyNotification::EVENT_REWARD_REDEEMED => 'Reward redeemed: {{reward_name}}',
            LoyaltyNotification::EVENT_VOUCHER_ISSUED => 'New voucher: {{voucher_name}}',
            LoyaltyNotification::EVENT_VOUCHER_REDEEMED => 'Voucher redeemed: {{voucher_name}}',
            LoyaltyNotification::EVENT_TIER_UPGRADED => 'Welcome to {{tier_name}}',
            LoyaltyNotification::EVENT_CAMPAIGN_ACTIVATED => 'Campaign live: {{campaign_name}}',
            default => 'Loyalty update',
        };
    }

    private function defaultContent(string $eventType): string
    {
        return match ($eventType) {
            LoyaltyNotification::EVENT_POINT_EARNED => 'Hi {{member_name}}, you earned {{points}} points. Your balance is now {{current_balance}}.',
            LoyaltyNotification::EVENT_POINT_REDEEMED => 'Hi {{member_name}}, you redeemed {{points}} points. Your balance is now {{current_balance}}.',
            LoyaltyNotification::EVENT_POINT_EXPIRED => 'Hi {{member_name}}, {{points}} points have expired. Your balance is now {{current_balance}}.',
            LoyaltyNotification::EVENT_REWARD_REDEEMED => 'Hi {{member_name}}, you redeemed {{reward_name}} for {{points}} points.',
            LoyaltyNotification::EVENT_VOUCHER_ISSUED => 'Hi {{member_name}}, a new voucher {{voucher_name}} is available for you.',
            LoyaltyNotification::EVENT_VOUCHER_REDEEMED => 'Hi {{member_name}}, your voucher {{voucher_name}} has been redeemed.',
            LoyaltyNotification::EVENT_TIER_UPGRADED => 'Hi {{member_name}}, congratulations! You are now a {{tier_name}} member.',
            LoyaltyNotification::EVENT_CAMPAIGN_ACTIVATED => 'Hi {{member_name}}, the campaign {{campaign_name}} is now active for you.',
            default => 'Hi {{member_name}}, you have a new loyalty update.',
        };
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
