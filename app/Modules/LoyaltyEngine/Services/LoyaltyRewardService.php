<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LoyaltyRewardService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return Collection<int, LoyaltyReward>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = LoyaltyReward::query()
            ->where('outlet_id', $outletId)
            ->orderByRaw('sort_order IS NULL, sort_order ASC')
            ->orderBy('points_cost')
            ->orderBy('name');

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->get();
    }

    /**
     * @return Collection<int, LoyaltyReward>
     */
    public function listActiveForOutlet(int $outletId): Collection
    {
        return LoyaltyReward::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderBy('points_cost')
            ->orderBy('name')
            ->get();
    }

    public function findScoped(?User $user, int $rewardId): ?LoyaltyReward
    {
        $reward = LoyaltyReward::query()->whereKey($rewardId)->first();
        if ($reward === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $reward->outlet_id);

        return $reward;
    }

    public function countActiveForOutlet(int $outletId): int
    {
        return (int) LoyaltyReward::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyReward
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);
        $pointsCost = (int) ($payload['pointsCost'] ?? 0);
        if ($pointsCost <= 0) {
            throw ValidationException::withMessages([
                'pointsCost' => ['Points cost must be greater than zero.'],
            ]);
        }

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $this->assertCodeUnique($outletId, $code);

        return LoyaltyReward::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'points_cost' => $pointsCost,
            'is_active' => $payload['isActive'] ?? true,
            'sort_order' => isset($payload['sortOrder']) ? (int) $payload['sortOrder'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyReward $reward, array $payload): LoyaltyReward
    {
        $this->assertOutletAllowed($user, (int) $reward->outlet_id);

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
        if (array_key_exists('pointsCost', $payload)) {
            $pointsCost = (int) $payload['pointsCost'];
            if ($pointsCost <= 0) {
                throw ValidationException::withMessages([
                    'pointsCost' => ['Points cost must be greater than zero.'],
                ]);
            }
            $attributes['points_cost'] = $pointsCost;
        }
        if (array_key_exists('sortOrder', $payload)) {
            $attributes['sort_order'] = $payload['sortOrder'] !== null ? (int) $payload['sortOrder'] : null;
        }
        if (array_key_exists('code', $payload)) {
            $code = strtoupper(trim((string) $payload['code']));
            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => ['Code is required.'],
                ]);
            }
            $this->assertCodeUnique((int) $reward->outlet_id, $code, (int) $reward->id);
            $attributes['code'] = $code;
        }

        if ($attributes !== []) {
            $reward->update($attributes);
        }

        return $reward->fresh();
    }

    public function setActive(?User $user, LoyaltyReward $reward, bool $isActive): LoyaltyReward
    {
        $this->assertOutletAllowed($user, (int) $reward->outlet_id);
        $reward->update(['is_active' => $isActive]);

        return $reward->fresh();
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = LoyaltyReward::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Reward code must be unique for this outlet.'],
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
