<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class MemberSegmentService
{
    /** @var list<string> */
    private const EXPIRABLE_EARNING_TYPES = [
        LoyaltyMemberLedger::TYPE_EARN,
        LoyaltyMemberLedger::TYPE_VISIT_REWARD,
        LoyaltyMemberLedger::TYPE_PERIOD_REWARD,
    ];

    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return Collection<int, MemberSegment>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = MemberSegment::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name');

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->get();
    }

    public function findScoped(?User $user, int $segmentId): ?MemberSegment
    {
        $segment = MemberSegment::query()->whereKey($segmentId)->first();
        if ($segment === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $segment->outlet_id);

        return $segment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): MemberSegment
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $segmentType = (string) ($payload['segmentType'] ?? '');
        $config = $this->normalizeConfig($segmentType, $payload['config'] ?? []);

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId, $code);

        return MemberSegment::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'segment_type' => $segmentType,
            'config_json' => $config,
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, MemberSegment $segment, array $payload): MemberSegment
    {
        $this->assertOutletAllowed($user, (int) $segment->outlet_id);

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
            $this->assertCodeUnique((int) $segment->outlet_id, $code, (int) $segment->id);
            $attributes['code'] = $code;
        }
        if (array_key_exists('segmentType', $payload) || array_key_exists('config', $payload)) {
            $segmentType = (string) ($payload['segmentType'] ?? $segment->segment_type);
            $config = $this->normalizeConfig(
                $segmentType,
                $payload['config'] ?? $segment->config(),
            );
            $attributes['segment_type'] = $segmentType;
            $attributes['config_json'] = $config;
        }

        if ($attributes !== []) {
            $segment->update($attributes);
        }

        return $segment->fresh() ?? $segment;
    }

    public function setActive(?User $user, MemberSegment $segment, bool $isActive): MemberSegment
    {
        $this->assertOutletAllowed($user, (int) $segment->outlet_id);
        $segment->update(['is_active' => $isActive]);

        return $segment->fresh() ?? $segment;
    }

    public function countMembers(MemberSegment $segment, ?Carbon $asOf = null): int
    {
        if (! $segment->is_active) {
            return 0;
        }

        if ($segment->segment_type === MemberSegment::TYPE_EXPIRING_SOON) {
            return $this->countExpiringSoonMembers($segment, $asOf);
        }

        return (int) $this->membersQuery($segment, $asOf)->count();
    }

    /**
     * @return array{count: int, members: Collection<int, Member>}
     */
    public function preview(MemberSegment $segment, int $limit = 50, ?Carbon $asOf = null): array
    {
        if (! $segment->is_active) {
            return ['count' => 0, 'members' => new Collection()];
        }

        if ($segment->segment_type === MemberSegment::TYPE_EXPIRING_SOON) {
            return $this->previewExpiringSoon($segment, $limit, $asOf);
        }

        $query = $this->membersQuery($segment, $asOf);
        $count = (int) (clone $query)->count();

        return [
            'count' => $count,
            'members' => $query->orderBy('full_name')->limit($limit)->get(),
        ];
    }

    /**
     * @return list<int>
     */
    public function memberIds(MemberSegment $segment, ?Carbon $asOf = null): array
    {
        if (! $segment->is_active) {
            return [];
        }

        $asOf ??= now();
        $ids = [];

        if ($segment->segment_type === MemberSegment::TYPE_EXPIRING_SOON) {
            Member::query()
                ->where('outlet_id', $segment->outlet_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->chunkById(100, function ($members) use ($segment, $asOf, &$ids): void {
                    foreach ($members as $member) {
                        if ($this->memberMatches($segment, $member, $asOf)) {
                            $ids[] = (int) $member->id;
                        }
                    }
                });

            return $ids;
        }

        return $this->membersQuery($segment, $asOf)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function memberMatches(MemberSegment $segment, Member $member, ?Carbon $asOf = null): bool
    {
        if (! $segment->is_active || (int) $member->outlet_id !== (int) $segment->outlet_id) {
            return false;
        }

        $asOf ??= now();

        return match ($segment->segment_type) {
            MemberSegment::TYPE_VIP_SPENDER => $this->matchesVipSpender($member, $segment->config(), $asOf),
            MemberSegment::TYPE_FREQUENT_VISITOR => $this->matchesFrequentVisitor($member, $segment->config(), $asOf),
            MemberSegment::TYPE_BIRTHDAY_MONTH => $this->matchesBirthdayMonth($member, $asOf),
            MemberSegment::TYPE_INACTIVE_MEMBER => $this->matchesInactiveMember($member, $segment->config(), $asOf),
            MemberSegment::TYPE_NEVER_REDEEMED => $this->matchesNeverRedeemed($member, (int) $segment->outlet_id),
            MemberSegment::TYPE_EXPIRING_SOON => $this->matchesExpiringSoon($member, (int) $segment->outlet_id, $segment->config(), $asOf),
            default => false,
        };
    }

    /**
     * @return Collection<int, MemberSegment>
     */
    public function segmentsForMember(Member $member, int $outletId, ?Carbon $asOf = null): Collection
    {
        if ((int) $member->outlet_id !== $outletId) {
            return new Collection();
        }

        $asOf ??= now();

        return MemberSegment::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (MemberSegment $segment): bool => $this->memberMatches($segment, $member, $asOf))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $rawConfig
     * @return array<string, mixed>
     */
    private function normalizeConfig(string $segmentType, array $rawConfig): array
    {
        if (! in_array($segmentType, MemberSegment::TYPES, true)) {
            throw ValidationException::withMessages([
                'segmentType' => ['Invalid segment type.'],
            ]);
        }

        return match ($segmentType) {
            MemberSegment::TYPE_VIP_SPENDER => $this->normalizeVipSpenderConfig($rawConfig),
            MemberSegment::TYPE_FREQUENT_VISITOR => $this->normalizeFrequentVisitorConfig($rawConfig),
            MemberSegment::TYPE_BIRTHDAY_MONTH => [],
            MemberSegment::TYPE_INACTIVE_MEMBER => $this->normalizeInactiveMemberConfig($rawConfig),
            MemberSegment::TYPE_NEVER_REDEEMED => [],
            MemberSegment::TYPE_EXPIRING_SOON => $this->normalizeExpiringSoonConfig($rawConfig),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function membersQuery(MemberSegment $segment, ?Carbon $asOf = null): Builder
    {
        $asOf ??= now();
        $outletId = (int) $segment->outlet_id;
        $config = $segment->config();

        $query = Member::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true);

        return match ($segment->segment_type) {
            MemberSegment::TYPE_VIP_SPENDER => $this->applyVipSpenderScope($query, $config),
            MemberSegment::TYPE_FREQUENT_VISITOR => $this->applyFrequentVisitorScope($query, $config),
            MemberSegment::TYPE_BIRTHDAY_MONTH => $this->applyBirthdayMonthScope($query, $asOf),
            MemberSegment::TYPE_INACTIVE_MEMBER => $this->applyInactiveMemberScope($query, $config, $asOf),
            MemberSegment::TYPE_NEVER_REDEEMED => $this->applyNeverRedeemedScope($query, $outletId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyVipSpenderScope(Builder $query, array $config): Builder
    {
        $minimum = (float) ($config['minimum_spending'] ?? 0);

        return $query->whereRaw(
            '(SELECT COALESCE(SUM(total_amount), 0) FROM member_transactions WHERE member_transactions.member_id = members.id) >= ?',
            [$minimum],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyFrequentVisitorScope(Builder $query, array $config): Builder
    {
        $minimum = (int) ($config['minimum_visits'] ?? 0);

        return $query->whereRaw(
            '(SELECT COUNT(*) FROM member_transactions WHERE member_transactions.member_id = members.id) >= ?',
            [$minimum],
        );
    }

    private function applyBirthdayMonthScope(Builder $query, Carbon $asOf): Builder
    {
        $month = (int) $asOf->month;

        return $query->where(function (Builder $scoped) use ($month): void {
            $scoped->whereMonth('birth_date', $month)
                ->orWhereMonth('birthday', $month);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function applyInactiveMemberScope(Builder $query, array $config, Carbon $asOf): Builder
    {
        $inactiveDays = (int) ($config['inactive_days'] ?? 0);
        $cutoff = $asOf->copy()->subDays($inactiveDays)->toDateTimeString();

        return $query->where(function (Builder $scoped) use ($cutoff): void {
            $scoped->whereDoesntHave('transactions')
                ->orWhereRaw(
                    '(SELECT MAX(transaction_at) FROM member_transactions WHERE member_transactions.member_id = members.id) < ?',
                    [$cutoff],
                );
        });
    }

    private function applyNeverRedeemedScope(Builder $query, int $outletId): Builder
    {
        return $query->whereDoesntHave('rewardRedemptions', function (Builder $redemptions) use ($outletId): void {
            $redemptions->where('outlet_id', $outletId);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesVipSpender(Member $member, array $config, Carbon $asOf): bool
    {
        unset($asOf);
        $minimum = (float) ($config['minimum_spending'] ?? 0);
        $total = (float) MemberTransaction::query()
            ->where('member_id', $member->id)
            ->sum('total_amount');

        return $total >= $minimum;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesFrequentVisitor(Member $member, array $config, Carbon $asOf): bool
    {
        unset($asOf);
        $minimum = (int) ($config['minimum_visits'] ?? 0);

        return (int) MemberTransaction::query()
            ->where('member_id', $member->id)
            ->count() >= $minimum;
    }

    private function matchesBirthdayMonth(Member $member, Carbon $asOf): bool
    {
        $birth = $member->birth_date ?? $member->birthday;
        if ($birth === null) {
            return false;
        }

        return (int) $birth->month === (int) $asOf->month;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesInactiveMember(Member $member, array $config, Carbon $asOf): bool
    {
        $inactiveDays = (int) ($config['inactive_days'] ?? 0);
        $cutoff = $asOf->copy()->subDays($inactiveDays);
        $lastVisit = MemberTransaction::query()
            ->where('member_id', $member->id)
            ->max('transaction_at');

        if ($lastVisit === null) {
            return true;
        }

        return Carbon::parse($lastVisit)->lt($cutoff);
    }

    private function matchesNeverRedeemed(Member $member, int $outletId): bool
    {
        return ! LoyaltyRewardRedemption::query()
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function matchesExpiringSoon(Member $member, int $outletId, array $config, Carbon $asOf): bool
    {
        $daysBeforeExpiry = (int) ($config['days_before_expiry'] ?? 0);
        if ($daysBeforeExpiry < 1) {
            return false;
        }

        $programs = $this->expiryProgramsForOutlet($outletId);
        if ($programs->isEmpty()) {
            return false;
        }

        $programExpiryDays = $programs->keyBy('id')->map(fn (LoyaltyProgram $program): int => (int) $program->expiry_days);
        $programIds = $programs->pluck('id')->all();

        $earnings = LoyaltyMemberLedger::query()
            ->where('member_id', $member->id)
            ->whereIn('loyalty_program_id', $programIds)
            ->whereIn('type', self::EXPIRABLE_EARNING_TYPES)
            ->where('points', '>', 0)
            ->get();

        $expiredReferenceIds = LoyaltyMemberLedger::query()
            ->where('member_id', $member->id)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->where('reference_type', 'expiry')
            ->pluck('reference_id')
            ->all();

        $windowEnd = $asOf->copy()->addDays($daysBeforeExpiry);

        foreach ($earnings as $earning) {
            $expiryDays = (int) ($programExpiryDays[(int) $earning->loyalty_program_id] ?? 0);
            if ($expiryDays < 1) {
                continue;
            }

            if (in_array((string) $earning->id, $expiredReferenceIds, true)) {
                continue;
            }

            $expiresAt = $earning->created_at->copy()->addDays($expiryDays);
            if ($expiresAt->lte($asOf)) {
                continue;
            }

            if ($expiresAt->lte($windowEnd)) {
                return true;
            }
        }

        return false;
    }

    private function countExpiringSoonMembers(MemberSegment $segment, ?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $count = 0;

        Member::query()
            ->where('outlet_id', $segment->outlet_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($members) use ($segment, $asOf, &$count): void {
                foreach ($members as $member) {
                    if ($this->memberMatches($segment, $member, $asOf)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * @return array{count: int, members: Collection<int, Member>}
     */
    private function previewExpiringSoon(MemberSegment $segment, int $limit, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $matched = new Collection();
        $count = 0;

        Member::query()
            ->where('outlet_id', $segment->outlet_id)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->chunkById(100, function ($members) use ($segment, $asOf, $limit, &$matched, &$count): void {
                foreach ($members as $member) {
                    if (! $this->memberMatches($segment, $member, $asOf)) {
                        continue;
                    }

                    $count++;
                    if ($matched->count() < $limit) {
                        $matched->push($member);
                    }
                }
            });

        return ['count' => $count, 'members' => $matched];
    }

    /**
     * @return Collection<int, LoyaltyProgram>
     */
    private function expiryProgramsForOutlet(int $outletId): Collection
    {
        return LoyaltyProgram::query()
            ->where('expiry_enabled', true)
            ->where('expiry_days', '>', 0)
            ->where('is_active', true)
            ->where(function (Builder $scoped) use ($outletId): void {
                $scoped->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->get();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeVipSpenderConfig(array $config): array
    {
        $minimum = (float) ($config['minimum_spending'] ?? 0);
        if ($minimum <= 0) {
            throw ValidationException::withMessages([
                'config.minimum_spending' => ['Minimum spending must be greater than zero.'],
            ]);
        }

        return ['minimum_spending' => $minimum];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeFrequentVisitorConfig(array $config): array
    {
        $minimum = (int) ($config['minimum_visits'] ?? 0);
        if ($minimum < 1) {
            throw ValidationException::withMessages([
                'config.minimum_visits' => ['Minimum visits must be at least 1.'],
            ]);
        }

        return ['minimum_visits' => $minimum];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeInactiveMemberConfig(array $config): array
    {
        $days = (int) ($config['inactive_days'] ?? 0);
        if ($days < 1) {
            throw ValidationException::withMessages([
                'config.inactive_days' => ['Inactive days must be at least 1.'],
            ]);
        }

        return ['inactive_days' => $days];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeExpiringSoonConfig(array $config): array
    {
        $days = (int) ($config['days_before_expiry'] ?? 0);
        if ($days < 1) {
            throw ValidationException::withMessages([
                'config.days_before_expiry' => ['Days before expiry must be at least 1.'],
            ]);
        }

        return ['days_before_expiry' => $days];
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = MemberSegment::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Segment code must be unique for this outlet.'],
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
