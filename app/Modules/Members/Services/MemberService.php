<?php

namespace App\Modules\Members\Services;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyProgramService;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Modules\LoyaltyEngine\Services\LoyaltyRewardService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyBalanceProjectionService $loyaltyBalanceProjectionService,
        private readonly LoyaltyRewardService $loyaltyRewardService,
        private readonly LoyaltyProgramService $loyaltyProgramService,
    ) {}

    /**
     * @return Collection<int, Member>
     */
    public function listForOutlet(?User $user, ?int $outletId): Collection
    {
        $query = Member::query()->orderBy('full_name')->orderBy('name');

        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
            $query->where('outlet_id', $outletId);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, Member>
     */
    public function search(?User $user, int $outletId, string $term, int $limit = 20): Collection
    {
        $this->assertOutletAllowed($user, $outletId);
        $needle = trim($term);
        if ($needle === '') {
            return Member::query()
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->orderBy('full_name')
                ->limit($limit)
                ->get();
        }

        $like = '%'.$needle.'%';

        return Member::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->where(function ($query) use ($like): void {
                $query->where('full_name', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('member_no', 'like', $like);
            })
            ->orderBy('full_name')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(?User $user, array $payload): Member
    {
        $outletId = (int) ($payload['outletId'] ?? $payload['outlet_id'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required for member creation.'],
            ]);
        }
        $this->assertOutletAllowed($user, $outletId);

        $fullName = trim((string) ($payload['fullName'] ?? $payload['name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        if ($fullName === '' || $phone === '') {
            throw ValidationException::withMessages([
                'fullName' => ['Full name and phone are required.'],
            ]);
        }

        return DB::transaction(function () use ($outletId, $fullName, $phone, $payload): Member {
            $member = Member::query()->create([
                'outlet_id' => $outletId,
                'member_no' => $this->generateMemberNo($outletId),
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $payload['email'] ?? null,
                'birth_date' => $payload['birthDate'] ?? $payload['birthday'] ?? null,
                'gender' => $payload['gender'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'is_active' => $payload['isActive'] ?? (($payload['status'] ?? 'active') === 'active'),
                'points' => 0,
            ]);

            return $member->fresh();
        });
    }

    public function findForOutlet(?User $user, int $memberId, ?int $outletId = null): ?Member
    {
        $member = Member::query()->whereKey($memberId)->first();
        if ($member === null) {
            return null;
        }

        if ($outletId !== null && $outletId > 0 && (int) $member->outlet_id !== $outletId) {
            return null;
        }

        if ($member->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $member->outlet_id);
        }

        return $member;
    }

    /**
     * @return array<string,mixed>
     */
    public function profile(?User $user, int $memberId, ?int $outletId = null): array
    {
        $member = $this->findForOutlet($user, $memberId, $outletId);
        if ($member === null) {
            throw ValidationException::withMessages([
                'memberId' => ['Member not found.'],
            ]);
        }

        $transactions = MemberTransaction::query()
            ->where('member_id', $member->id)
            ->orderByDesc('transaction_at')
            ->limit(50)
            ->get();

        $aggregate = MemberTransaction::query()
            ->where('member_id', $member->id)
            ->selectRaw('COUNT(*) as visit_count, COALESCE(SUM(total_amount), 0) as total_spending, MAX(transaction_at) as last_visit')
            ->first();

        $loyaltyHistory = LoyaltyMemberLedger::query()
            ->where('member_id', $member->id)
            ->with('program')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $memberOutletId = (int) ($member->outlet_id ?? $outletId ?? 0);
        $availableRewards = $memberOutletId > 0
            ? $this->loyaltyRewardService->listActiveForOutlet($memberOutletId)
            : collect();

        $rewardRedemptions = $memberOutletId > 0
            ? LoyaltyRewardRedemption::query()
                ->where('member_id', $member->id)
                ->where('outlet_id', $memberOutletId)
                ->with('reward')
                ->orderByDesc('issued_at')
                ->limit(50)
                ->get()
            : collect();

        $expiryHistory = LoyaltyMemberLedger::query()
            ->where('member_id', $member->id)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $expiredPointsTotal = (int) abs((int) LoyaltyMemberLedger::query()
            ->where('member_id', $member->id)
            ->where('type', LoyaltyMemberLedger::TYPE_EXPIRED)
            ->sum('points'));

        return [
            'member' => $member,
            'stats' => [
                'totalVisits' => (int) ($aggregate->visit_count ?? 0),
                'totalSpending' => (float) ($aggregate->total_spending ?? 0),
                'lastVisit' => $aggregate->last_visit,
            ],
            'transactions' => $transactions,
            'currentPoints' => $this->loyaltyBalanceProjectionService->currentPointsForMember((int) $member->id),
            'loyaltyHistory' => $loyaltyHistory,
            'availableRewards' => $availableRewards,
            'rewardRedemptions' => $rewardRedemptions,
            'expiryPolicy' => $this->resolveExpiryPolicyForMember($member, $memberOutletId),
            'expiredPointsTotal' => $expiredPointsTotal,
            'expiryHistory' => $expiryHistory,
        ];
    }

    /**
     * @return array{enabled: bool, days: ?int}
     */
    private function resolveExpiryPolicyForMember(Member $member, int $memberOutletId): array
    {
        if ($memberOutletId < 1) {
            return ['enabled' => false, 'days' => null];
        }

        $program = $this->loyaltyProgramService->resolveActiveProgram(
            $memberOutletId,
            LoyaltyProgram::TYPE_SPEND_BASED,
        );

        if ($program === null) {
            $program = LoyaltyProgram::query()
                ->where(function ($scoped) use ($memberOutletId): void {
                    $scoped->where('outlet_id', $memberOutletId)->orWhereNull('outlet_id');
                })
                ->where('is_active', true)
                ->where('expiry_enabled', true)
                ->orderByDesc('id')
                ->first();
        }

        if ($program === null || ! $program->expiry_enabled || (int) $program->expiry_days < 1) {
            return ['enabled' => false, 'days' => null];
        }

        return [
            'enabled' => true,
            'days' => (int) $program->expiry_days,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(Member $member, array $payload): Member
    {
        $attributes = [];
        if (array_key_exists('fullName', $payload) || array_key_exists('name', $payload)) {
            $attributes['full_name'] = trim((string) ($payload['fullName'] ?? $payload['name'] ?? ''));
        }
        if (array_key_exists('phone', $payload)) {
            $attributes['phone'] = trim((string) $payload['phone']);
        }
        foreach (['email', 'gender', 'notes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }
        if (array_key_exists('birthDate', $payload) || array_key_exists('birthday', $payload)) {
            $attributes['birth_date'] = $payload['birthDate'] ?? $payload['birthday'] ?? null;
        }
        if (array_key_exists('isActive', $payload)) {
            $attributes['is_active'] = (bool) $payload['isActive'];
        } elseif (array_key_exists('status', $payload)) {
            $attributes['is_active'] = $payload['status'] === 'active';
        }

        $member->fill($attributes);
        $member->save();

        return $member->fresh();
    }

    public function toggleActive(Member $member): Member
    {
        $member->is_active = ! (bool) $member->is_active;
        $member->save();

        return $member->fresh();
    }

    private function generateMemberNo(int $outletId): string
    {
        $count = Member::query()->where('outlet_id', $outletId)->count();

        return sprintf('MEM-%05d', $count + 1);
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
