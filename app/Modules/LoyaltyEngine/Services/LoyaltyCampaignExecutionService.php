<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyCampaignExecutionService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly MemberSegmentService $segmentService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    public function activate(?User $user, LoyaltyCampaign $campaign, ?Carbon $activatedAt = null): LoyaltyCampaign
    {
        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);
        $this->assertStatusTransition($campaign->status, LoyaltyCampaign::STATUS_ACTIVE);

        $activatedAt ??= now();

        $campaign = DB::transaction(function () use ($campaign, $activatedAt): LoyaltyCampaign {
            $fresh = LoyaltyCampaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($fresh === null) {
                throw ValidationException::withMessages([
                    'campaign' => ['Campaign not found.'],
                ]);
            }

            if ($fresh->status === LoyaltyCampaign::STATUS_ACTIVE) {
                return $fresh->load('segment');
            }

            $this->assertStatusTransition($fresh->status, LoyaltyCampaign::STATUS_ACTIVE);
            $this->captureAudienceSnapshot($fresh, $activatedAt);

            $fresh->update([
                'status' => LoyaltyCampaign::STATUS_ACTIVE,
                'activated_at' => $activatedAt,
            ]);

            return $fresh->fresh(['segment']) ?? $fresh;
        });

        $this->notifyCampaignAudience($campaign);

        return $campaign;
    }

    private function notifyCampaignAudience(LoyaltyCampaign $campaign): void
    {
        $memberIds = LoyaltyCampaignAudience::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($memberIds === []) {
            return;
        }

        $campaignName = (string) $campaign->name;
        $outletId = (int) $campaign->outlet_id;

        foreach ($memberIds as $memberId) {
            $this->loyaltyNotificationService->dispatchCampaignActivated($outletId, $memberId, $campaignName);
        }
    }

    public function complete(?User $user, LoyaltyCampaign $campaign, ?Carbon $completedAt = null): LoyaltyCampaign
    {
        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);
        $this->assertStatusTransition($campaign->status, LoyaltyCampaign::STATUS_COMPLETED);

        $completedAt ??= now();

        $campaign->update([
            'status' => LoyaltyCampaign::STATUS_COMPLETED,
            'completed_at' => $completedAt,
        ]);

        return $campaign->fresh(['segment']) ?? $campaign;
    }

    public function cancel(?User $user, LoyaltyCampaign $campaign, ?Carbon $cancelledAt = null): LoyaltyCampaign
    {
        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);
        $this->assertStatusTransition($campaign->status, LoyaltyCampaign::STATUS_CANCELLED);

        $cancelledAt ??= now();

        $campaign->update([
            'status' => LoyaltyCampaign::STATUS_CANCELLED,
            'cancelled_at' => $cancelledAt,
        ]);

        return $campaign->fresh(['segment']) ?? $campaign;
    }

    public function captureAudienceSnapshot(LoyaltyCampaign $campaign, ?Carbon $capturedAt = null): int
    {
        $segment = $campaign->segment;
        if ($segment === null) {
            throw ValidationException::withMessages([
                'segmentId' => ['Campaign segment not found.'],
            ]);
        }

        $capturedAt ??= now();
        $memberIds = $this->segmentService->memberIds($segment, $capturedAt);
        if ($memberIds === []) {
            return 0;
        }

        $existing = LoyaltyCampaignAudience::query()
            ->where('campaign_id', $campaign->id)
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $existingSet = array_flip($existing);

        $rows = [];
        $now = now();
        foreach ($memberIds as $memberId) {
            if (isset($existingSet[$memberId])) {
                continue;
            }

            $rows[] = [
                'campaign_id' => $campaign->id,
                'member_id' => $memberId,
                'captured_at' => $capturedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            LoyaltyCampaignAudience::query()->insert($rows);
        }

        return count($rows);
    }

    public function countCapturedAudience(LoyaltyCampaign $campaign): int
    {
        return (int) LoyaltyCampaignAudience::query()
            ->where('campaign_id', $campaign->id)
            ->count();
    }

    /**
     * @return array{
     *     campaign: LoyaltyCampaign,
     *     capturedCount: int,
     *     members: Collection<int, Member>
     * }
     */
    public function audienceSnapshot(LoyaltyCampaign $campaign, int $limit = 50): array
    {
        $capturedCount = $this->countCapturedAudience($campaign);

        $members = Member::query()
            ->whereIn('id', function ($query) use ($campaign): void {
                $query->select('member_id')
                    ->from('loyalty_campaign_audiences')
                    ->where('campaign_id', $campaign->id);
            })
            ->orderBy('full_name')
            ->limit($limit)
            ->get();

        return [
            'campaign' => $campaign,
            'capturedCount' => $capturedCount,
            'members' => $members,
        ];
    }

    /**
     * @return Collection<int, LoyaltyCampaign>
     */
    public function dueScheduledCampaigns(?Carbon $asOf = null): Collection
    {
        $asOf ??= now();

        return LoyaltyCampaign::query()
            ->with('segment')
            ->where('status', LoyaltyCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $asOf)
            ->orderBy('scheduled_at')
            ->get();
    }

    public function processDueScheduledCampaigns(?Carbon $asOf = null): int
    {
        $processed = 0;

        foreach ($this->dueScheduledCampaigns($asOf) as $campaign) {
            $this->activate(null, $campaign, $asOf);
            $processed++;
        }

        return $processed;
    }

    private function assertStatusTransition(string $current, string $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = match ($current) {
            LoyaltyCampaign::STATUS_DRAFT => [
                LoyaltyCampaign::STATUS_SCHEDULED,
                LoyaltyCampaign::STATUS_ACTIVE,
                LoyaltyCampaign::STATUS_CANCELLED,
            ],
            LoyaltyCampaign::STATUS_SCHEDULED => [
                LoyaltyCampaign::STATUS_ACTIVE,
                LoyaltyCampaign::STATUS_CANCELLED,
            ],
            LoyaltyCampaign::STATUS_ACTIVE => [
                LoyaltyCampaign::STATUS_COMPLETED,
                LoyaltyCampaign::STATUS_CANCELLED,
            ],
            default => [],
        };

        if (! in_array($next, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from {$current} to {$next}."],
            ]);
        }
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
