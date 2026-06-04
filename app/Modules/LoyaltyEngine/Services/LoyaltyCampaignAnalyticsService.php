<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

class LoyaltyCampaignAnalyticsService
{
    public function __construct(
        private readonly LoyaltyCampaignService $campaignService,
        private readonly LoyaltyCampaignExecutionService $executionService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $campaigns = LoyaltyCampaign::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', [
                LoyaltyCampaign::STATUS_DRAFT,
                LoyaltyCampaign::STATUS_SCHEDULED,
                LoyaltyCampaign::STATUS_ACTIVE,
            ])
            ->orderBy('name')
            ->get();

        $campaignSummary = $campaigns->map(function (LoyaltyCampaign $campaign): array {
            return [
                'campaign' => $this->campaignPayload($campaign),
                'audienceCount' => $this->campaignService->audienceCount($campaign->load('segment')),
            ];
        })->values()->all();

        $allCampaigns = LoyaltyCampaign::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name')
            ->get();

        $campaignExecutionSummary = $allCampaigns->map(function (LoyaltyCampaign $campaign): array {
            return [
                'campaign' => $this->campaignPayload($campaign),
                'audienceCount' => $this->campaignService->audienceCount($campaign->load('segment')),
                'capturedCount' => $this->executionService->countCapturedAudience($campaign),
                'activatedAt' => $campaign->activated_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'campaignsCount' => (int) LoyaltyCampaign::query()->where('outlet_id', $outletId)->count(),
            'campaignSummary' => $campaignSummary,
            'activeCampaigns' => (int) LoyaltyCampaign::query()
                ->where('outlet_id', $outletId)
                ->where('status', LoyaltyCampaign::STATUS_ACTIVE)
                ->count(),
            'completedCampaigns' => (int) LoyaltyCampaign::query()
                ->where('outlet_id', $outletId)
                ->where('status', LoyaltyCampaign::STATUS_COMPLETED)
                ->count(),
            'scheduledCampaigns' => (int) LoyaltyCampaign::query()
                ->where('outlet_id', $outletId)
                ->where('status', LoyaltyCampaign::STATUS_SCHEDULED)
                ->count(),
            'campaignAudienceCaptured' => (int) LoyaltyCampaignAudience::query()
                ->whereIn('campaign_id', LoyaltyCampaign::query()->where('outlet_id', $outletId)->select('id'))
                ->count(),
            'campaignExecutionSummary' => $campaignExecutionSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignPayload(LoyaltyCampaign $campaign): array
    {
        return [
            'id' => (string) $campaign->id,
            'code' => (string) $campaign->code,
            'name' => (string) $campaign->name,
            'status' => (string) $campaign->status,
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
