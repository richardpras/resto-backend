<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

class LoyaltyTierAnalyticsService
{
    public function __construct(
        private readonly LoyaltyTierService $loyaltyTierService,
        private readonly TierBenefitService $tierBenefitService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array{
     *     tiersCount: int,
     *     tierSummary: list<array{tier: array<string, mixed>, memberCount: int}>,
     *     tierBenefitSummary: list<array{tier: string, members: int, benefits: int}>
     * }
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $tiers = LoyaltyTier::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderByDesc('sort_order')
            ->orderBy('name')
            ->get();

        $tierSummary = $tiers->map(function (LoyaltyTier $tier): array {
            return [
                'tier' => [
                    'id' => (string) $tier->id,
                    'code' => (string) $tier->code,
                    'name' => (string) $tier->name,
                    'qualificationType' => (string) $tier->qualification_type,
                ],
                'memberCount' => $this->loyaltyTierService->countMembersInTier($tier),
            ];
        })->values()->all();

        return [
            'tiersCount' => (int) LoyaltyTier::query()->where('outlet_id', $outletId)->count(),
            'tierSummary' => $tierSummary,
            'tierBenefitSummary' => $this->tierBenefitService->tierBenefitSummary($tiers, $this->loyaltyTierService),
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
