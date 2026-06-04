<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MemberVoucherService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyVoucherService $voucherService,
        private readonly LoyaltyNotificationService $loyaltyNotificationService,
    ) {}

    /**
     * @return Collection<int, MemberVoucher>
     */
    public function listForMember(?User $user, Member $member, ?int $outletId = null): Collection
    {
        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
            if ((int) $member->outlet_id !== $outletId) {
                return new Collection();
            }
        } elseif ($member->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $member->outlet_id);
        }

        return MemberVoucher::query()
            ->with('voucher')
            ->where('member_id', $member->id)
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->orderByDesc('issued_at')
            ->get();
    }

    public function findScoped(?User $user, int $memberVoucherId): ?MemberVoucher
    {
        $memberVoucher = MemberVoucher::query()->with(['voucher', 'member'])->whereKey($memberVoucherId)->first();
        if ($memberVoucher === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);

        return $memberVoucher;
    }

    public function issue(
        ?User $user,
        Member $member,
        LoyaltyVoucher $voucher,
        ?string $notes = null,
        ?Carbon $issuedAt = null,
    ): MemberVoucher {
        $outletId = (int) $member->outlet_id;
        if ($outletId < 1 || (int) $voucher->outlet_id !== $outletId) {
            throw ValidationException::withMessages([
                'memberId' => ['Member and voucher must belong to the same outlet.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);
        $this->voucherService->validateVoucherWindow($voucher, $issuedAt);

        $issuedAt ??= now();

        $issued = MemberVoucher::query()->create([
            'outlet_id' => $outletId,
            'member_id' => $member->id,
            'voucher_id' => $voucher->id,
            'voucher_code' => $this->generateUniqueVoucherCode($voucher->code),
            'status' => MemberVoucher::STATUS_ISSUED,
            'issued_at' => $issuedAt,
            'notes' => $notes,
        ])->load('voucher');

        $this->loyaltyNotificationService->dispatchVoucherIssued(
            $outletId,
            (int) $member->id,
            (string) $voucher->name,
        );

        return $issued;
    }

    public function updateStatus(?User $user, MemberVoucher $memberVoucher, string $status): MemberVoucher
    {
        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);

        if (! in_array($status, MemberVoucher::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid voucher status.'],
            ]);
        }

        return match ($status) {
            MemberVoucher::STATUS_CLAIMED => $this->claim($user, $memberVoucher),
            MemberVoucher::STATUS_REDEEMED => $this->redeem($user, $memberVoucher),
            MemberVoucher::STATUS_EXPIRED => $this->expire($user, $memberVoucher),
            MemberVoucher::STATUS_CANCELLED => $this->cancel($user, $memberVoucher),
            default => throw ValidationException::withMessages([
                'status' => ['Use issue flow for issued status.'],
            ]),
        };
    }

    public function claim(?User $user, MemberVoucher $memberVoucher): MemberVoucher
    {
        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);
        $this->assertTransition($memberVoucher->status, MemberVoucher::STATUS_CLAIMED);

        $memberVoucher->update([
            'status' => MemberVoucher::STATUS_CLAIMED,
            'claimed_at' => now(),
        ]);

        return $memberVoucher->fresh(['voucher']) ?? $memberVoucher;
    }

    public function redeem(?User $user, MemberVoucher $memberVoucher): MemberVoucher
    {
        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);
        $this->assertTransition($memberVoucher->status, MemberVoucher::STATUS_REDEEMED);

        $memberVoucher->update([
            'status' => MemberVoucher::STATUS_REDEEMED,
            'redeemed_at' => now(),
        ]);

        $fresh = $memberVoucher->fresh(['voucher']) ?? $memberVoucher;
        $this->loyaltyNotificationService->dispatchVoucherRedeemed(
            (int) $memberVoucher->outlet_id,
            (int) $memberVoucher->member_id,
            (string) ($fresh->voucher?->name ?? $fresh->voucher_code),
        );

        app(LoyaltyAutomationService::class)->safeProcessEvent(
            (int) $memberVoucher->outlet_id,
            (int) $memberVoucher->member_id,
            LoyaltyAutomation::TRIGGER_VOUCHER_REDEEMED,
        );

        return $fresh;
    }

    public function expire(?User $user, MemberVoucher $memberVoucher): MemberVoucher
    {
        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);
        $this->assertTransition($memberVoucher->status, MemberVoucher::STATUS_EXPIRED);

        $memberVoucher->update([
            'status' => MemberVoucher::STATUS_EXPIRED,
            'expired_at' => now(),
        ]);

        return $memberVoucher->fresh(['voucher']) ?? $memberVoucher;
    }

    public function cancel(?User $user, MemberVoucher $memberVoucher): MemberVoucher
    {
        $this->assertOutletAllowed($user, (int) $memberVoucher->outlet_id);
        $this->assertTransition($memberVoucher->status, MemberVoucher::STATUS_CANCELLED);

        $memberVoucher->update([
            'status' => MemberVoucher::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        return $memberVoucher->fresh(['voucher']) ?? $memberVoucher;
    }

    /**
     * @return Collection<int, MemberVoucher>
     */
    public function availableForMember(Member $member, int $outletId): Collection
    {
        return MemberVoucher::query()
            ->with('voucher')
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->whereIn('status', [MemberVoucher::STATUS_ISSUED, MemberVoucher::STATUS_CLAIMED])
            ->orderByDesc('issued_at')
            ->get();
    }

    /**
     * @return Collection<int, MemberVoucher>
     */
    public function historyForMember(Member $member, int $outletId, int $limit = 50): Collection
    {
        return MemberVoucher::query()
            ->with('voucher')
            ->where('member_id', $member->id)
            ->where('outlet_id', $outletId)
            ->orderByDesc('issued_at')
            ->limit($limit)
            ->get();
    }

    public function campaignIssuanceExists(int $campaignId, int $voucherId, int $memberId): bool
    {
        return MemberVoucher::query()
            ->where('voucher_id', $voucherId)
            ->where('member_id', $memberId)
            ->where('notes', MemberVoucher::campaignNote($campaignId))
            ->exists();
    }

    public function countCampaignIssuance(int $campaignId): int
    {
        return (int) MemberVoucher::query()
            ->where('notes', MemberVoucher::campaignNote($campaignId))
            ->count();
    }

    private function generateUniqueVoucherCode(string $definitionCode): string
    {
        $prefix = strtoupper(Str::before($definitionCode, '-') ?: $definitionCode);
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', $prefix) ?: 'VIP', 0, 8);

        do {
            $code = $prefix.'-'.strtoupper(Str::random(6));
        } while (MemberVoucher::query()->where('voucher_code', $code)->exists());

        return $code;
    }

    private function assertTransition(string $current, string $next): void
    {
        if ($current === $next) {
            return;
        }

        $allowed = match ($current) {
            MemberVoucher::STATUS_ISSUED => [
                MemberVoucher::STATUS_CLAIMED,
                MemberVoucher::STATUS_EXPIRED,
                MemberVoucher::STATUS_CANCELLED,
            ],
            MemberVoucher::STATUS_CLAIMED => [
                MemberVoucher::STATUS_REDEEMED,
                MemberVoucher::STATUS_EXPIRED,
                MemberVoucher::STATUS_CANCELLED,
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
