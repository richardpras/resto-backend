<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;

class LoyaltyProgramService
{
    public function resolveActiveProgram(int $outletId, string $type): ?LoyaltyProgram
    {
        $now = now();

        $programs = LoyaltyProgram::query()
            ->where('is_active', true)
            ->where('type', $type)
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $now->toDateString());
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $now->toDateString());
            })
            ->with('activeRule')
            ->get();

        if ($programs->isEmpty()) {
            return null;
        }

        return $programs
            ->sortBy(fn (LoyaltyProgram $program): int => $program->outlet_id === null ? 1 : 0)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRuleConfig(LoyaltyProgram $program): array
    {
        $rule = $program->relationLoaded('activeRule')
            ? $program->activeRule
            : LoyaltyProgramRule::query()
                ->where('loyalty_program_id', $program->id)
                ->when($program->type !== null, fn ($query) => $query->where('rule_type', $program->type))
                ->orderByDesc('id')
                ->first();

        return is_array($rule?->config) ? $rule->config : [];
    }

    public function calculateSpendBasedPoints(float $orderTotal, array $config): int
    {
        $earnPerAmount = (float) ($config['earnPerAmount'] ?? 0);
        $pointsEarned = (int) ($config['pointsEarned'] ?? 0);

        if ($earnPerAmount <= 0 || $pointsEarned <= 0 || $orderTotal <= 0) {
            return 0;
        }

        return (int) floor($orderTotal / $earnPerAmount) * $pointsEarned;
    }

    public function calculatePeriodSpendingPoints(float $totalSpend, array $config): int
    {
        $minimumSpend = (float) ($config['minimum_spend'] ?? 0);
        $rewardPercent = (float) (
            $config['reward_percent']
            ?? $config['rewardPercent']
            ?? $config['percentage']
            ?? 0
        );

        if ($totalSpend < $minimumSpend || $rewardPercent <= 0) {
            return 0;
        }

        return (int) floor($totalSpend * ($rewardPercent / 100));
    }
}
