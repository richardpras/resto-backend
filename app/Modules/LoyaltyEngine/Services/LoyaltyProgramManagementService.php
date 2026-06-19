<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\User;
use App\Modules\LoyaltyEngine\Support\LoyaltyRuleConfigValidator;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyProgramManagementService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly LoyaltyProgramService $loyaltyProgramService,
        private readonly LoyaltyRuleConfigValidator $ruleConfigValidator,
    ) {}

    /**
     * @return Collection<int, LoyaltyProgram>
     */
    public function list(?User $user, ?int $outletId = null, ?string $type = null, ?bool $isActive = null): Collection
    {
        $query = LoyaltyProgram::query()
            ->withCount('rules')
            ->with('activeRule')
            ->orderByDesc('id');

        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
            $query->where(function ($scoped) use ($outletId): void {
                $scoped->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        } elseif ($user !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if ($allowed !== null) {
                $query->where(function ($scoped) use ($allowed): void {
                    $scoped->whereIn('outlet_id', $allowed)->orWhereNull('outlet_id');
                });
            }
        }

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->get();
    }

    public function findScoped(?User $user, int $programId): ?LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->with(['rules', 'activeRule'])->whereKey($programId)->first();
        if ($program === null) {
            return null;
        }

        if ($program->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $program->outlet_id);
        }

        return $program;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyProgram
    {
        $outletId = isset($payload['outletId']) ? (int) $payload['outletId'] : null;
        if ($outletId !== null && $outletId > 0) {
            $this->assertOutletAllowed($user, $outletId);
        }

        $type = (string) ($payload['type'] ?? LoyaltyProgram::TYPE_SPEND_BASED);
        $this->assertProgramType($type);

        $expiry = $this->resolveExpiryAttributes($payload);

        return DB::transaction(function () use ($payload, $outletId, $type, $expiry): LoyaltyProgram {
            $program = LoyaltyProgram::query()->create([
                'outlet_id' => $outletId > 0 ? $outletId : null,
                'code' => (string) $payload['code'],
                'name' => (string) $payload['name'],
                'description' => $payload['description'] ?? null,
                'type' => $type,
                'is_active' => array_key_exists('isActive', $payload) ? (bool) $payload['isActive'] : true,
                'expiry_enabled' => $expiry['expiry_enabled'],
                'expiry_days' => $expiry['expiry_days'],
                'effective_from' => $payload['effectiveFrom'] ?? null,
                'effective_until' => $payload['effectiveUntil'] ?? null,
            ]);

            if (array_key_exists('ruleConfig', $payload)) {
                $this->upsertActiveRule($program, is_array($payload['ruleConfig']) ? $payload['ruleConfig'] : []);
            }

            return $program->fresh(['activeRule', 'rules']) ?? $program;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyProgram $program, array $payload): LoyaltyProgram
    {
        if ($program->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $program->outlet_id);
        }

        $attributes = [];
        if (array_key_exists('name', $payload)) {
            $attributes['name'] = (string) $payload['name'];
        }
        if (array_key_exists('description', $payload)) {
            $attributes['description'] = $payload['description'];
        }
        if (array_key_exists('code', $payload)) {
            $attributes['code'] = (string) $payload['code'];
        }
        if (array_key_exists('type', $payload)) {
            $this->assertProgramType((string) $payload['type']);
            $attributes['type'] = (string) $payload['type'];
        }
        if (array_key_exists('outletId', $payload)) {
            $outletId = $payload['outletId'] !== null ? (int) $payload['outletId'] : null;
            if ($outletId !== null && $outletId > 0) {
                $this->assertOutletAllowed($user, $outletId);
            }
            $attributes['outlet_id'] = $outletId > 0 ? $outletId : null;
        }
        if (array_key_exists('effectiveFrom', $payload)) {
            $attributes['effective_from'] = $payload['effectiveFrom'];
        }
        if (array_key_exists('effectiveUntil', $payload)) {
            $attributes['effective_until'] = $payload['effectiveUntil'];
        }
        if (array_key_exists('expiryEnabled', $payload) || array_key_exists('expiryDays', $payload)) {
            $expiry = $this->resolveExpiryAttributes(array_merge([
                'expiryEnabled' => $program->expiry_enabled,
                'expiryDays' => $program->expiry_days,
            ], $payload));
            $attributes['expiry_enabled'] = $expiry['expiry_enabled'];
            $attributes['expiry_days'] = $expiry['expiry_days'];
        }

        if ($attributes !== []) {
            $program->fill($attributes);
            $program->save();
        }

        if (array_key_exists('ruleConfig', $payload)) {
            $this->upsertActiveRule($program, is_array($payload['ruleConfig']) ? $payload['ruleConfig'] : []);
        }

        return $program->fresh(['activeRule', 'rules']) ?? $program;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function upsertActiveRule(LoyaltyProgram $program, array $config): ?LoyaltyProgramRule
    {
        if ($program->type === LoyaltyProgram::TYPE_MANUAL) {
            return null;
        }

        if ($config === []) {
            return null;
        }

        $validated = $this->ruleConfigValidator->validate((string) $program->type, $config);

        $existing = LoyaltyProgramRule::query()
            ->where('loyalty_program_id', $program->id)
            ->orderByDesc('id')
            ->first();

        if ($existing instanceof LoyaltyProgramRule) {
            $existing->update([
                'rule_type' => (string) $program->type,
                'config' => $validated,
            ]);

            return $existing->fresh();
        }

        return LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => (string) $program->type,
            'config' => $validated,
        ]);
    }

    public function setActive(?User $user, LoyaltyProgram $program, bool $isActive): LoyaltyProgram
    {
        if ($program->outlet_id !== null) {
            $this->assertOutletAllowed($user, (int) $program->outlet_id);
        }

        $program->update(['is_active' => $isActive]);

        return $program->fresh() ?? $program;
    }

    public function resolveActiveForOutlet(int $outletId, string $type): ?LoyaltyProgram
    {
        return $this->loyaltyProgramService->resolveActiveProgram($outletId, $type);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{expiry_enabled: bool, expiry_days: ?int}
     */
    private function resolveExpiryAttributes(array $payload): array
    {
        $enabled = array_key_exists('expiryEnabled', $payload)
            ? (bool) $payload['expiryEnabled']
            : false;

        if (! $enabled) {
            return ['expiry_enabled' => false, 'expiry_days' => null];
        }

        $days = isset($payload['expiryDays']) ? (int) $payload['expiryDays'] : 0;
        if ($days < 1) {
            throw ValidationException::withMessages([
                'expiryDays' => ['Expiry days must be greater than zero when expiry is enabled.'],
            ]);
        }

        return ['expiry_enabled' => true, 'expiry_days' => $days];
    }

    private function assertProgramType(string $type): void
    {
        $allowed = [
            LoyaltyProgram::TYPE_SPEND_BASED,
            LoyaltyProgram::TYPE_PERIOD_SPENDING,
            LoyaltyProgram::TYPE_VISIT_BASED,
            LoyaltyProgram::TYPE_MANUAL,
            LoyaltyProgram::TYPE_PERCENTAGE_REWARD,
        ];

        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages([
                'type' => ['Invalid program type.'],
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
