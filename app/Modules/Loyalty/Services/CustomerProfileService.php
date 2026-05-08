<?php

namespace App\Modules\Loyalty\Services;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerProfileService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @return array<int, LoyaltyAccount> */
    public function list(User $user, ?int $outletId = null): array
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $query = LoyaltyAccount::query()->whereIn('outlet_id', $allowed)->whereNull('merged_into_account_id');
        if ($outletId !== null) {
            $query->where('outlet_id', $outletId);
        }

        return $query->orderBy('name')->get()->all();
    }

    public function create(User $user, array $payload): LoyaltyAccount
    {
        $outletId = (int) $payload['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        $globalUuid = isset($payload['globalCustomerUuid']) && is_string($payload['globalCustomerUuid']) && trim($payload['globalCustomerUuid']) !== ''
            ? trim($payload['globalCustomerUuid'])
            : (string) Str::uuid();

        $account = LoyaltyAccount::query()->create([
            'outlet_id' => $outletId,
            'customer_uuid' => (string) Str::uuid(),
            'global_customer_uuid' => $globalUuid,
            'name' => (string) $payload['name'],
            'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
            'email' => isset($payload['email']) ? (string) $payload['email'] : null,
            'points_balance' => 0,
            'lifetime_points_earned' => 0,
            'lifetime_points_redeemed' => 0,
            'lifetime_spend' => 0,
            'lifetime_visits' => 0,
            'last_activity_at' => now(),
        ]);

        return $account->fresh(['currentTier']) ?? $account;
    }

    public function findScoped(User $user, int $customerId): LoyaltyAccount
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $account = LoyaltyAccount::query()
            ->whereIn('outlet_id', $allowed)
            ->whereNull('merged_into_account_id')
            ->find($customerId);

        if (! $account instanceof LoyaltyAccount) {
            throw (new ModelNotFoundException)->setModel(LoyaltyAccount::class, [$customerId]);
        }

        return $account;
    }

    /** @return array{source: LoyaltyAccount,target: LoyaltyAccount} */
    public function merge(User $user, LoyaltyAccount $source, int $targetCustomerId): array
    {
        $target = $this->findScoped($user, $targetCustomerId);
        if ((int) $source->outlet_id !== (int) $target->outlet_id) {
            throw ValidationException::withMessages([
                'targetCustomerId' => ['Merge target must be in the same outlet.'],
            ]);
        }
        if ((int) $source->id === (int) $target->id) {
            throw ValidationException::withMessages([
                'targetCustomerId' => ['Source and target customers must be different.'],
            ]);
        }

        return DB::transaction(function () use ($source, $target): array {
            $lockedSource = LoyaltyAccount::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $lockedTarget = LoyaltyAccount::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $lockedTarget->update([
                'points_balance' => (int) $lockedTarget->points_balance + (int) $lockedSource->points_balance,
                'lifetime_points_earned' => (int) $lockedTarget->lifetime_points_earned + (int) $lockedSource->lifetime_points_earned,
                'lifetime_points_redeemed' => (int) $lockedTarget->lifetime_points_redeemed + (int) $lockedSource->lifetime_points_redeemed,
                'lifetime_spend' => (float) $lockedTarget->lifetime_spend + (float) $lockedSource->lifetime_spend,
                'lifetime_visits' => (int) $lockedTarget->lifetime_visits + (int) $lockedSource->lifetime_visits,
                'last_activity_at' => now(),
            ]);

            $lockedSource->ledgers()->update(['loyalty_account_id' => (int) $lockedTarget->id]);
            $lockedSource->redemptions()->update(['loyalty_account_id' => (int) $lockedTarget->id]);
            $lockedSource->update([
                'merged_into_account_id' => (int) $lockedTarget->id,
                'points_balance' => 0,
            ]);

            return [
                'source' => $lockedSource->fresh() ?? $lockedSource,
                'target' => $lockedTarget->fresh(['currentTier']) ?? $lockedTarget,
            ];
        });
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }
}
