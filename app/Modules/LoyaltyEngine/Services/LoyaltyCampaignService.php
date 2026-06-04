<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LoyaltyCampaignService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly MemberSegmentService $segmentService,
        private readonly LoyaltyCampaignExecutionService $executionService,
        private readonly CampaignVoucherIssuanceService $campaignVoucherIssuanceService,
    ) {}

    /**
     * @return Collection<int, LoyaltyCampaign>
     */
    public function list(?User $user, int $outletId, ?string $status = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = LoyaltyCampaign::query()
            ->with('segment')
            ->where('outlet_id', $outletId)
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get()->map(function (LoyaltyCampaign $campaign): LoyaltyCampaign {
            $campaign->setAttribute('audience_count', $this->audienceCount($campaign));
            $campaign->setAttribute('captured_count', $this->executionService->countCapturedAudience($campaign));
            $campaign->setAttribute('issued_voucher_count', $this->campaignVoucherIssuanceService->countIssuedForCampaign((int) $campaign->id));

            return $campaign;
        });
    }

    public function findScoped(?User $user, int $campaignId): ?LoyaltyCampaign
    {
        $campaign = LoyaltyCampaign::query()->with('segment')->whereKey($campaignId)->first();
        if ($campaign === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyCampaign
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId, $code);
        $segment = $this->resolveSegmentForOutlet($user, $outletId, (int) ($payload['segmentId'] ?? 0));

        return LoyaltyCampaign::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'segment_id' => $segment->id,
            'campaign_type' => trim((string) ($payload['campaignType'] ?? LoyaltyCampaign::TYPE_AUDIENCE)),
            'scheduled_at' => $payload['scheduledAt'] ?? null,
            'status' => LoyaltyCampaign::STATUS_DRAFT,
        ])->load('segment');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyCampaign $campaign, array $payload): LoyaltyCampaign
    {
        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);
        $this->assertEditable($campaign);

        $attributes = [];
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Name is required.'],
                ]);
            }
            $attributes['name'] = $name;
        }
        if (array_key_exists('description', $payload)) {
            $attributes['description'] = $payload['description'];
        }
        if (array_key_exists('code', $payload)) {
            $code = strtoupper(trim((string) $payload['code']));
            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => ['Code is required.'],
                ]);
            }
            $this->assertCodeUnique((int) $campaign->outlet_id, $code, (int) $campaign->id);
            $attributes['code'] = $code;
        }
        if (array_key_exists('segmentId', $payload)) {
            $segment = $this->resolveSegmentForOutlet(
                $user,
                (int) $campaign->outlet_id,
                (int) $payload['segmentId'],
            );
            $attributes['segment_id'] = $segment->id;
        }
        if (array_key_exists('campaignType', $payload)) {
            $attributes['campaign_type'] = trim((string) $payload['campaignType']);
        }
        if (array_key_exists('scheduledAt', $payload)) {
            $attributes['scheduled_at'] = $payload['scheduledAt'];
        }

        if ($attributes !== []) {
            $campaign->update($attributes);
        }

        return $campaign->fresh(['segment']) ?? $campaign;
    }

    public function updateStatus(?User $user, LoyaltyCampaign $campaign, string $status): LoyaltyCampaign
    {
        $this->assertOutletAllowed($user, (int) $campaign->outlet_id);

        if (! in_array($status, LoyaltyCampaign::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid campaign status.'],
            ]);
        }

        return match ($status) {
            LoyaltyCampaign::STATUS_ACTIVE => $this->executionService->activate($user, $campaign),
            LoyaltyCampaign::STATUS_COMPLETED => $this->executionService->complete($user, $campaign),
            LoyaltyCampaign::STATUS_CANCELLED => $this->executionService->cancel($user, $campaign),
            default => $this->applySimpleStatusUpdate($campaign, $status),
        };
    }

    private function applySimpleStatusUpdate(LoyaltyCampaign $campaign, string $status): LoyaltyCampaign
    {
        $this->assertStatusTransition($campaign->status, $status);
        $campaign->update(['status' => $status]);

        return $campaign->fresh(['segment']) ?? $campaign;
    }

    public function audienceCount(LoyaltyCampaign $campaign): int
    {
        $segment = $campaign->segment;
        if ($segment === null) {
            return 0;
        }

        return $this->segmentService->countMembers($segment);
    }

    /**
     * @return array{
     *     campaign: LoyaltyCampaign,
     *     segment: MemberSegment,
     *     memberCount: int,
     *     members: Collection<int, \App\Models\Member>
     * }
     */
    public function audiencePreview(LoyaltyCampaign $campaign, int $limit = 50): array
    {
        $segment = $campaign->segment;
        if ($segment === null) {
            throw ValidationException::withMessages([
                'segmentId' => ['Campaign segment not found.'],
            ]);
        }

        $preview = $this->segmentService->preview($segment, $limit);

        return [
            'campaign' => $campaign,
            'segment' => $segment,
            'memberCount' => $preview['count'],
            'members' => $preview['members'],
        ];
    }

    private function resolveSegmentForOutlet(?User $user, int $outletId, int $segmentId): MemberSegment
    {
        if ($segmentId < 1) {
            throw ValidationException::withMessages([
                'segmentId' => ['Segment is required.'],
            ]);
        }

        $segment = MemberSegment::query()->whereKey($segmentId)->first();
        if ($segment === null || (int) $segment->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'segmentId' => ['Segment not found for this outlet.'],
            ]);
        }

        return $segment;
    }

    private function assertEditable(LoyaltyCampaign $campaign): void
    {
        if (in_array($campaign->status, [LoyaltyCampaign::STATUS_COMPLETED, LoyaltyCampaign::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled campaigns cannot be edited.'],
            ]);
        }
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

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = LoyaltyCampaign::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Campaign code must be unique for this outlet.'],
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
