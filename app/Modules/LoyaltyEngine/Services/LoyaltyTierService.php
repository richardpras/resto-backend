<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyTierService
{
    /** @var list<string> */
    private const LIFETIME_POINT_TYPES = [
        LoyaltyMemberLedger::TYPE_EARN,
        LoyaltyMemberLedger::TYPE_VISIT_REWARD,
        LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
    ];

    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
        private readonly TierBenefitService $tierBenefitService,
    ) {}

    /**
     * @return Collection<int, LoyaltyTier>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        return LoyaltyTier::query()
            ->where('outlet_id', $outletId)
            ->when($isActive !== null, fn ($query) => $query->where('is_active', $isActive))
            ->orderByDesc('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function findScoped(?User $user, int $tierId): ?LoyaltyTier
    {
        $tier = LoyaltyTier::query()->whereKey($tierId)->first();
        if ($tier === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $tier->outlet_id);

        return $tier;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyTier
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

        $qualificationType = (string) ($payload['qualificationType'] ?? '');
        $qualificationConfig = $this->normalizeQualificationConfig(
            $qualificationType,
            $payload['qualificationConfig'] ?? [],
        );

        $this->assertCodeUnique($outletId, $code);

        return LoyaltyTier::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'qualification_type' => $qualificationType,
            'qualification_config' => $qualificationConfig,
            'benefit_config_json' => $this->tierBenefitService->normalizeConfig(
                is_array($payload['benefitConfig'] ?? null) ? $payload['benefitConfig'] : [],
            ),
            'sort_order' => (int) ($payload['sortOrder'] ?? 0),
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyTier $tier, array $payload): LoyaltyTier
    {
        $this->assertOutletAllowed($user, (int) $tier->outlet_id);

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
            $this->assertCodeUnique((int) $tier->outlet_id, $code, (int) $tier->id);
            $attributes['code'] = $code;
        }

        if (array_key_exists('sortOrder', $payload)) {
            $attributes['sort_order'] = (int) $payload['sortOrder'];
        }

        $qualificationType = array_key_exists('qualificationType', $payload)
            ? (string) $payload['qualificationType']
            : (string) $tier->qualification_type;

        if (array_key_exists('qualificationType', $payload) || array_key_exists('qualificationConfig', $payload)) {
            $attributes['qualification_type'] = $qualificationType;
            $attributes['qualification_config'] = $this->normalizeQualificationConfig(
                $qualificationType,
                $payload['qualificationConfig'] ?? $tier->qualificationConfig(),
            );
        }

        if (array_key_exists('benefitConfig', $payload)) {
            $attributes['benefit_config_json'] = $this->tierBenefitService->normalizeConfig(
                is_array($payload['benefitConfig']) ? $payload['benefitConfig'] : [],
            );
        }

        if ($attributes !== []) {
            $tier->update($attributes);
        }

        return $tier->fresh() ?? $tier;
    }

    public function setActive(?User $user, LoyaltyTier $tier, bool $isActive): LoyaltyTier
    {
        $this->assertOutletAllowed($user, (int) $tier->outlet_id);
        $tier->update(['is_active' => $isActive]);

        return $tier->fresh() ?? $tier;
    }

    /**
     * @return Collection<int, LoyaltyTier>
     */
    public function activeTiersForOutlet(int $outletId): Collection
    {
        return LoyaltyTier::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderByDesc('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function currentAssignment(int $memberId, int $outletId): ?MemberTierHistory
    {
        return MemberTierHistory::query()
            ->with('tier')
            ->where('member_id', $memberId)
            ->where('outlet_id', $outletId)
            ->whereNull('removed_at')
            ->orderByDesc('assigned_at')
            ->first();
    }

    /**
     * @return Collection<int, MemberTierHistory>
     */
    public function historyForMember(Member $member, int $outletId, int $limit = 50): Collection
    {
        return MemberTierHistory::query()
            ->with('tier')
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->orderByDesc('assigned_at')
            ->limit($limit)
            ->get();
    }

    public function recalculateMemberTier(int $memberId, int $outletId, string $reason = MemberTierHistory::REASON_RECALCULATION): void
    {
        if ($outletId < 1) {
            return;
        }

        $upgradedTier = null;

        DB::transaction(function () use ($memberId, $outletId, $reason, &$upgradedTier): void {
            $metrics = $this->resolveMemberMetrics($memberId, $outletId);
            $targetTier = $this->determineHighestQualifiedTier($outletId, $metrics);

            $current = MemberTierHistory::query()
                ->with('tier')
                ->where('member_id', $memberId)
                ->where('outlet_id', $outletId)
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->orderByDesc('assigned_at')
                ->first();

            if ($targetTier === null) {
                if ($current !== null) {
                    $current->update([
                        'removed_at' => now(),
                        'reason' => $reason,
                    ]);
                }

                return;
            }

            if ($current !== null && (int) $current->tier_id === (int) $targetTier->id) {
                return;
            }

            $assignmentReason = $this->resolveAssignmentReason($current, $targetTier, $reason);

            if ($current !== null) {
                $current->update([
                    'removed_at' => now(),
                    'reason' => $assignmentReason,
                ]);
            }

            MemberTierHistory::query()->create([
                'outlet_id' => $outletId,
                'member_id' => $memberId,
                'tier_id' => $targetTier->id,
                'assigned_at' => now(),
                'reason' => $assignmentReason,
            ]);

            if ($assignmentReason === MemberTierHistory::REASON_AUTO_UPGRADE) {
                $upgradedTier = $targetTier;
            }
        });

        if ($upgradedTier instanceof LoyaltyTier) {
            $this->loyaltyNotificationService->dispatchTierUpgraded($outletId, $memberId, $upgradedTier);
            app(LoyaltyAutomationService::class)->safeProcessEvent(
                $outletId,
                $memberId,
                LoyaltyAutomation::TRIGGER_TIER_UPGRADED,
                ['tierName' => (string) $upgradedTier->name],
            );
        }
    }

    public function countMembersInTier(LoyaltyTier $tier): int
    {
        return (int) MemberTierHistory::query()
            ->where('tier_id', $tier->id)
            ->whereNull('removed_at')
            ->distinct('member_id')
            ->count('member_id');
    }

    /**
     * @return array{lifetimePoints: int, lifetimeSpending: float, visitCount: int}
     */
    public function resolveMemberMetrics(int $memberId, int $outletId): array
    {
        $lifetimePoints = (int) LoyaltyMemberLedger::query()
            ->where('member_id', $memberId)
            ->whereIn('type', self::LIFETIME_POINT_TYPES)
            ->where('points', '>', 0)
            ->sum('points');

        $transactionAggregate = MemberTransaction::query()
            ->where('member_id', $memberId)
            ->whereHas('member', fn ($query) => $query->where('outlet_id', $outletId))
            ->selectRaw('COUNT(*) as visit_count, COALESCE(SUM(total_amount), 0) as total_spending')
            ->first();

        return [
            'lifetimePoints' => $lifetimePoints,
            'lifetimeSpending' => (float) ($transactionAggregate->total_spending ?? 0),
            'visitCount' => (int) ($transactionAggregate->visit_count ?? 0),
        ];
    }

    /**
     * @param  array{lifetimePoints: int, lifetimeSpending: float, visitCount: int}  $metrics
     */
    public function qualifies(LoyaltyTier $tier, array $metrics): bool
    {
        $config = $tier->qualificationConfig();

        return match ($tier->qualification_type) {
            LoyaltyTier::TYPE_LIFETIME_POINTS => $metrics['lifetimePoints'] >= (int) ($config['minimum_points'] ?? 0),
            LoyaltyTier::TYPE_LIFETIME_SPENDING => $metrics['lifetimeSpending'] >= (float) ($config['minimum_spending'] ?? 0),
            LoyaltyTier::TYPE_VISIT_COUNT => $metrics['visitCount'] >= (int) ($config['minimum_visits'] ?? 0),
            default => false,
        };
    }

    /**
     * @param  array{lifetimePoints: int, lifetimeSpending: float, visitCount: int}  $metrics
     */
    public function determineHighestQualifiedTier(int $outletId, array $metrics): ?LoyaltyTier
    {
        $qualified = $this->activeTiersForOutlet($outletId)
            ->filter(fn (LoyaltyTier $tier): bool => $this->qualifies($tier, $metrics));

        if ($qualified->isEmpty()) {
            return null;
        }

        return $qualified
            ->sort(fn (LoyaltyTier $a, LoyaltyTier $b): int => ((int) $b->sort_order <=> (int) $a->sort_order)
                ?: ((int) $b->id <=> (int) $a->id))
            ->values()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int|float>
     */
    private function normalizeQualificationConfig(string $qualificationType, array $config): array
    {
        if (! in_array($qualificationType, LoyaltyTier::QUALIFICATION_TYPES, true)) {
            throw ValidationException::withMessages([
                'qualificationType' => ['Invalid qualification type.'],
            ]);
        }

        return match ($qualificationType) {
            LoyaltyTier::TYPE_LIFETIME_POINTS => $this->normalizeMinimumPoints($config),
            LoyaltyTier::TYPE_LIFETIME_SPENDING => $this->normalizeMinimumSpending($config),
            LoyaltyTier::TYPE_VISIT_COUNT => $this->normalizeMinimumVisits($config),
            default => throw ValidationException::withMessages([
                'qualificationType' => ['Invalid qualification type.'],
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{minimum_points: int}
     */
    private function normalizeMinimumPoints(array $config): array
    {
        $minimum = (int) ($config['minimum_points'] ?? 0);
        if ($minimum < 1) {
            throw ValidationException::withMessages([
                'qualificationConfig.minimum_points' => ['Minimum points must be at least 1.'],
            ]);
        }

        return ['minimum_points' => $minimum];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{minimum_spending: float}
     */
    private function normalizeMinimumSpending(array $config): array
    {
        $minimum = (float) ($config['minimum_spending'] ?? 0);
        if ($minimum <= 0) {
            throw ValidationException::withMessages([
                'qualificationConfig.minimum_spending' => ['Minimum spending must be greater than zero.'],
            ]);
        }

        return ['minimum_spending' => $minimum];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{minimum_visits: int}
     */
    private function normalizeMinimumVisits(array $config): array
    {
        $minimum = (int) ($config['minimum_visits'] ?? 0);
        if ($minimum < 1) {
            throw ValidationException::withMessages([
                'qualificationConfig.minimum_visits' => ['Minimum visits must be at least 1.'],
            ]);
        }

        return ['minimum_visits' => $minimum];
    }

    private function resolveAssignmentReason(
        ?MemberTierHistory $current,
        LoyaltyTier $targetTier,
        string $fallbackReason,
    ): string {
        if ($current === null || $current->tier === null) {
            return $fallbackReason;
        }

        if ((int) $targetTier->sort_order > (int) $current->tier->sort_order) {
            return MemberTierHistory::REASON_AUTO_UPGRADE;
        }

        return MemberTierHistory::REASON_RECALCULATION;
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = LoyaltyTier::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Tier code must be unique for this outlet.'],
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
