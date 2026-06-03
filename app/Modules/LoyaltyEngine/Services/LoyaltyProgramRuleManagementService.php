<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\User;
use App\Modules\LoyaltyEngine\Support\LoyaltyRuleConfigValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LoyaltyProgramRuleManagementService
{
    public function __construct(
        private readonly LoyaltyProgramManagementService $programManagementService,
        private readonly LoyaltyRuleConfigValidator $configValidator,
    ) {}

    /**
     * @return Collection<int, LoyaltyProgramRule>
     */
    public function listByProgram(?User $user, int $programId): Collection
    {
        $program = $this->programManagementService->findScoped($user, $programId);
        if ($program === null) {
            throw ValidationException::withMessages([
                'loyaltyProgramId' => ['Loyalty program not found.'],
            ]);
        }

        return LoyaltyProgramRule::query()
            ->where('loyalty_program_id', $program->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, int $programId, array $payload): LoyaltyProgramRule
    {
        $program = $this->programManagementService->findScoped($user, $programId);
        if ($program === null) {
            throw ValidationException::withMessages([
                'loyaltyProgramId' => ['Loyalty program not found.'],
            ]);
        }

        $ruleType = (string) ($payload['ruleType'] ?? $program->type);
        $this->assertRuleTypeAllowed($ruleType);
        $this->assertRuleTypeMatchesProgram($program, $ruleType);

        $config = $this->configValidator->validate(
            $ruleType,
            is_array($payload['config'] ?? null) ? $payload['config'] : [],
        );

        return LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $program->id,
            'rule_type' => $ruleType,
            'config' => $config,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyProgramRule $rule, array $payload): LoyaltyProgramRule
    {
        $program = $this->programManagementService->findScoped($user, (int) $rule->loyalty_program_id);
        if ($program === null) {
            throw ValidationException::withMessages([
                'ruleId' => ['Loyalty program not found.'],
            ]);
        }

        $ruleType = (string) ($payload['ruleType'] ?? $rule->rule_type ?? $program->type);
        $this->assertRuleTypeAllowed($ruleType);
        $this->assertRuleTypeMatchesProgram($program, $ruleType);

        $config = $this->configValidator->validate(
            $ruleType,
            is_array($payload['config'] ?? null) ? $payload['config'] : (array) $rule->config,
        );

        $rule->update([
            'rule_type' => $ruleType,
            'config' => $config,
        ]);

        return $rule->fresh() ?? $rule;
    }

    public function delete(?User $user, LoyaltyProgramRule $rule): void
    {
        $program = $this->programManagementService->findScoped($user, (int) $rule->loyalty_program_id);
        if ($program === null) {
            throw ValidationException::withMessages([
                'ruleId' => ['Loyalty program not found.'],
            ]);
        }

        $rule->delete();
    }

    private function assertRuleTypeAllowed(string $ruleType): void
    {
        if (! in_array($ruleType, LoyaltyProgramRule::RULE_TYPES, true)) {
            throw ValidationException::withMessages([
                'ruleType' => ['Invalid rule type.'],
            ]);
        }
    }

    private function assertRuleTypeMatchesProgram(LoyaltyProgram $program, string $ruleType): void
    {
        if ($program->type !== $ruleType) {
            throw ValidationException::withMessages([
                'ruleType' => ['Rule type must match the parent program type ('.$program->type.').'],
            ]);
        }
    }
}
