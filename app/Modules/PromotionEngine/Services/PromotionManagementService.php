<?php

namespace App\Modules\PromotionEngine\Services;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use App\Models\User;
use App\Modules\PromotionEngine\Services\PromotionUsageService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PromotionManagementService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PromotionEvaluationService $evaluationService,
        private readonly PromotionUsageService $usageService,
    ) {}

    /**
     * @return Collection<int, Promotion>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = Promotion::query()
            ->where('outlet_id', $outletId)
            ->orderByDesc('priority')
            ->orderBy('name');

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->get();
    }

    public function findScoped(?User $user, int $promotionId): ?Promotion
    {
        $promotion = Promotion::query()->whereKey($promotionId)->first();
        if ($promotion === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $promotion->outlet_id);

        return $promotion;
    }

    public function findActiveForOutlet(int $promotionId, int $outletId): Promotion
    {
        $promotion = Promotion::query()
            ->whereKey($promotionId)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->first();

        if ($promotion === null) {
            throw ValidationException::withMessages([
                'promotionId' => ['Promotion not found or inactive for this outlet.'],
            ]);
        }

        $this->validatePromotionWindow($promotion);

        return $promotion;
    }

    public function findActiveByCodeForOutlet(string $code, int $outletId): Promotion
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'code' => ['Promotion code is required.'],
            ]);
        }

        $promotion = Promotion::query()
            ->where('outlet_id', $outletId)
            ->where('code', $normalized)
            ->where('is_active', true)
            ->first();

        if ($promotion === null) {
            throw ValidationException::withMessages([
                'code' => ['Promotion not found or inactive for this outlet.'],
            ]);
        }

        $this->validatePromotionWindow($promotion);

        return $promotion;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): Promotion
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

        $type = (string) ($payload['type'] ?? '');
        $this->assertType($type);
        $config = $this->normalizeConfig($type, $payload['config'] ?? []);
        $conditions = $this->normalizeConditions($payload['conditions'] ?? []);

        $this->assertCodeUnique($outletId, $code);
        $this->assertWindow($payload['validFrom'] ?? null, $payload['validUntil'] ?? null);

        return Promotion::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'type' => $type,
            'config' => $config,
            'conditions' => $conditions,
            'priority' => (int) ($payload['priority'] ?? 0),
            'is_combinable' => (bool) ($payload['isCombinable'] ?? false),
            'exclusive' => (bool) ($payload['exclusive'] ?? false),
            'valid_from' => $payload['validFrom'] ?? null,
            'valid_until' => $payload['validUntil'] ?? null,
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, Promotion $promotion, array $payload): Promotion
    {
        $this->assertOutletAllowed($user, (int) $promotion->outlet_id);

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
            $this->assertCodeUnique((int) $promotion->outlet_id, $code, (int) $promotion->id);
            $attributes['code'] = $code;
        }
        if (array_key_exists('type', $payload)) {
            $this->assertType((string) $payload['type']);
            $attributes['type'] = (string) $payload['type'];
        }
        if (array_key_exists('config', $payload)) {
            $type = (string) ($attributes['type'] ?? $promotion->type);
            $attributes['config'] = $this->normalizeConfig($type, $payload['config']);
        }
        if (array_key_exists('conditions', $payload)) {
            $attributes['conditions'] = $this->normalizeConditions($payload['conditions']);
        }
        if (array_key_exists('priority', $payload)) {
            $attributes['priority'] = (int) $payload['priority'];
        }
        if (array_key_exists('isCombinable', $payload)) {
            $attributes['is_combinable'] = (bool) $payload['isCombinable'];
        }
        if (array_key_exists('exclusive', $payload)) {
            $attributes['exclusive'] = (bool) $payload['exclusive'];
        }
        if (array_key_exists('validFrom', $payload)) {
            $attributes['valid_from'] = $payload['validFrom'];
        }
        if (array_key_exists('validUntil', $payload)) {
            $attributes['valid_until'] = $payload['validUntil'];
        }

        if (array_key_exists('validFrom', $payload) || array_key_exists('validUntil', $payload)) {
            $this->assertWindow(
                $attributes['valid_from'] ?? $promotion->valid_from,
                $attributes['valid_until'] ?? $promotion->valid_until,
            );
        }

        if ($attributes !== []) {
            $promotion->update($attributes);
        }

        return $promotion->fresh() ?? $promotion;
    }

    public function setActive(?User $user, Promotion $promotion, bool $isActive): Promotion
    {
        $this->assertOutletAllowed($user, (int) $promotion->outlet_id);
        $promotion->update(['is_active' => $isActive]);

        return $promotion->fresh() ?? $promotion;
    }

    /**
     * @param  array<int, array{id: string, name?: string, price: float, qty: float, category?: string|null}>  $cartLines
     * @return array{
     *     subtotal: float,
     *     candidates: list<array<string, mixed>>,
     *     best: array<string, mixed>|null
     * }
     */
    public function evaluateForOutlet(?User $user, int $outletId, array $cartLines, float $subtotal): array
    {
        $this->assertOutletAllowed($user, $outletId);

        $promotions = Promotion::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (Promotion $promotion): bool => $this->usageService->hasDailyCapacity($promotion, $outletId));

        $candidates = $this->evaluationService->evaluateCandidates($promotions, $cartLines, $subtotal);

        return [
            'subtotal' => $subtotal,
            'candidates' => $candidates,
            'best' => $candidates[0] ?? null,
        ];
    }

    public function validatePromotionWindow(Promotion $promotion, ?Carbon $asOf = null): void
    {
        $asOf ??= now();

        if ($promotion->valid_from !== null && $asOf->lt($promotion->valid_from)) {
            throw ValidationException::withMessages([
                'promotionId' => ['Promotion is not yet valid.'],
            ]);
        }

        if ($promotion->valid_until !== null && $asOf->gt($promotion->valid_until)) {
            throw ValidationException::withMessages([
                'promotionId' => ['Promotion validity has ended.'],
            ]);
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, Promotion::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => ['Invalid promotion type.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(string $type, array $config): array
    {
        return match ($type) {
            Promotion::TYPE_PERCENTAGE_ORDER, Promotion::TYPE_PERCENTAGE_ITEMS => [
                'rate' => max(0.0, min(100.0, (float) ($config['rate'] ?? 0))),
                'maxDiscount' => isset($config['maxDiscount']) ? max(0.0, (float) $config['maxDiscount']) : null,
                'menuItemIds' => $this->normalizeStringList($config['menuItemIds'] ?? []),
            ],
            Promotion::TYPE_FIXED_AMOUNT => [
                'amount' => max(0.0, (float) ($config['amount'] ?? 0)),
            ],
            Promotion::TYPE_BUY_X_GET_Y => [
                'buyQty' => max(1, (int) ($config['buyQty'] ?? 1)),
                'getQty' => max(1, (int) ($config['getQty'] ?? 1)),
                'menuItemIds' => $this->normalizeStringList($config['menuItemIds'] ?? []),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditions(array $conditions): array
    {
        return [
            'minSpend' => max(0.0, (float) ($conditions['minSpend'] ?? 0)),
            'menuItemIds' => $this->normalizeStringList($conditions['menuItemIds'] ?? []),
            'categories' => $this->normalizeStringList($conditions['categories'] ?? []),
            'dayRestriction' => $this->normalizeStringList($conditions['dayRestriction'] ?? []),
            'timeStart' => isset($conditions['timeStart']) ? (string) $conditions['timeStart'] : null,
            'timeEnd' => isset($conditions['timeEnd']) ? (string) $conditions['timeEnd'] : null,
            'usageLimitPerDay' => max(0, (int) ($conditions['usageLimitPerDay'] ?? 0)),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $item): string => (string) $item,
            array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''),
        )));
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = Promotion::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Promotion code must be unique for this outlet.'],
            ]);
        }
    }

    private function assertWindow(mixed $validFrom, mixed $validUntil): void
    {
        if ($validFrom === null || $validUntil === null) {
            return;
        }

        if (Carbon::parse($validFrom)->gt(Carbon::parse($validUntil))) {
            throw ValidationException::withMessages([
                'validUntil' => ['Valid until must be after valid from.'],
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
