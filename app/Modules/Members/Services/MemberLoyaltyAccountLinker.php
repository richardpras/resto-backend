<?php

namespace App\Modules\Members\Services;

use App\Models\Member;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use Illuminate\Support\Str;

class MemberLoyaltyAccountLinker
{
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone)) ?? '';
    }

    public function findMemberByLoyaltyAccountId(int $loyaltyAccountId): ?Member
    {
        return Member::query()
            ->where('loyalty_account_id', $loyaltyAccountId)
            ->first();
    }

    public function ensureForMember(Member $member): LoyaltyAccount
    {
        $member->loadMissing('loyaltyAccount');

        if ($member->loyalty_account_id !== null && $member->loyaltyAccount instanceof LoyaltyAccount) {
            $this->syncProfileToAccount($member, $member->loyaltyAccount);
            $member->loyaltyAccount->refresh();

            return $member->loyaltyAccount;
        }

        $outletId = (int) ($member->outlet_id ?? 0);
        if ($outletId < 1) {
            throw new \InvalidArgumentException('Member outlet_id is required to link a loyalty account.');
        }

        $normalizedPhone = $this->normalizePhone((string) $member->phone);
        $existingAccount = $this->findUnlinkedAccountByPhone($outletId, $normalizedPhone, (string) $member->phone);

        if ($existingAccount instanceof LoyaltyAccount) {
            return $this->linkExisting($member, $existingAccount);
        }

        $account = LoyaltyAccount::query()->create([
            'outlet_id' => $outletId,
            'customer_uuid' => (string) Str::uuid(),
            'global_customer_uuid' => (string) Str::uuid(),
            'name' => $member->displayName(),
            'phone' => $member->phone,
            'email' => $member->email,
            'points_balance' => 0,
            'lifetime_points_earned' => 0,
            'lifetime_points_redeemed' => 0,
            'lifetime_spend' => 0,
            'lifetime_visits' => 0,
            'last_activity_at' => now(),
        ]);

        return $this->linkExisting($member, $account);
    }

    public function linkExisting(Member $member, LoyaltyAccount $account): LoyaltyAccount
    {
        if (
            $account->id !== $member->loyalty_account_id
            && Member::query()
                ->where('loyalty_account_id', $account->id)
                ->where('id', '!=', $member->id)
                ->exists()
        ) {
            throw new \RuntimeException('Loyalty account is already linked to another member.');
        }

        $member->loyalty_account_id = (int) $account->id;
        $member->save();

        $this->syncProfileToAccount($member, $account);

        return $account->fresh(['currentTier']) ?? $account;
    }

    public function syncProfileToAccount(Member $member, LoyaltyAccount $account): void
    {
        $account->fill([
            'name' => $member->displayName(),
            'phone' => $member->phone,
            'email' => $member->email,
            'last_activity_at' => now(),
        ]);
        $account->save();
    }

    /**
     * @return array{linked: int, created: int, skipped: int}
     */
    public function backfillForOutlet(?int $outletId = null, bool $dryRun = false): array
    {
        $linked = 0;
        $created = 0;
        $skipped = 0;

        $query = Member::query()->whereNull('loyalty_account_id');
        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        foreach ($query->orderBy('id')->cursor() as $member) {
            if ((int) ($member->outlet_id ?? 0) < 1 || trim((string) $member->phone) === '') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $normalizedPhone = $this->normalizePhone((string) $member->phone);
                $existing = $this->findUnlinkedAccountByPhone(
                    (int) $member->outlet_id,
                    $normalizedPhone,
                    (string) $member->phone,
                );
                if ($existing instanceof LoyaltyAccount) {
                    $linked++;
                } else {
                    $created++;
                }

                continue;
            }

            try {
                $normalizedPhone = $this->normalizePhone((string) $member->phone);
                $existing = $this->findUnlinkedAccountByPhone(
                    (int) $member->outlet_id,
                    $normalizedPhone,
                    (string) $member->phone,
                );
                if ($existing instanceof LoyaltyAccount) {
                    $this->linkExisting($member, $existing);
                    $linked++;
                } else {
                    $this->ensureForMember($member);
                    $created++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return compact('linked', 'created', 'skipped');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function crmPointsLedgerForAccount(?LoyaltyAccount $account, int $limit = 50): array
    {
        if ($account === null) {
            return [];
        }

        return LoyaltyPointsLedger::query()
            ->where('loyalty_account_id', $account->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(static fn (LoyaltyPointsLedger $row): array => [
                'id' => (string) $row->id,
                'customerId' => (string) $row->loyalty_account_id,
                'deltaPoints' => (int) $row->points_delta,
                'reason' => (string) ($row->transaction_type ?? 'adjustment'),
                'referenceType' => $row->reference_type,
                'referenceId' => $row->reference_id !== null ? (string) $row->reference_id : null,
                'createdAt' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function findUnlinkedAccountByPhone(int $outletId, string $normalizedPhone, string $rawPhone): ?LoyaltyAccount
    {
        if ($normalizedPhone === '' && trim($rawPhone) === '') {
            return null;
        }

        $linkedIds = Member::query()
            ->whereNotNull('loyalty_account_id')
            ->pluck('loyalty_account_id');

        $candidates = LoyaltyAccount::query()
            ->where('outlet_id', $outletId)
            ->whereNull('merged_into_account_id')
            ->when($linkedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $linkedIds))
            ->get();

        foreach ($candidates as $account) {
            if ($this->normalizePhone((string) ($account->phone ?? '')) === $normalizedPhone) {
                return $account;
            }
            if ($normalizedPhone === '' && (string) $account->phone === $rawPhone) {
                return $account;
            }
        }

        return null;
    }
}
