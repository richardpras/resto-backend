<?php

namespace App\Modules\LoyaltyEngine\Support;

use Illuminate\Validation\ValidationException;

class LoyaltyRuleConfigValidator
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function validate(string $ruleType, array $config): array
    {
        return match ($ruleType) {
            'spend_based' => $this->validateSpendBased($config),
            'visit_based' => $this->validateVisitBased($config),
            'period_spending' => $this->validatePeriodSpending($config),
            'percentage_reward' => $this->validatePercentageReward($config),
            default => throw ValidationException::withMessages([
                'ruleType' => ['Unsupported rule type.'],
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validateSpendBased(array $config): array
    {
        $earnPerAmount = (float) ($config['earnPerAmount'] ?? 0);
        $pointsEarned = (int) ($config['pointsEarned'] ?? 0);

        if ($earnPerAmount <= 0 || $pointsEarned <= 0) {
            throw ValidationException::withMessages([
                'config' => ['spend_based rules require earnPerAmount > 0 and pointsEarned > 0.'],
            ]);
        }

        return [
            'earnPerAmount' => $earnPerAmount,
            'pointsEarned' => $pointsEarned,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validateVisitBased(array $config): array
    {
        $visitThreshold = (int) ($config['visit_threshold'] ?? $config['visitThreshold'] ?? 0);
        $pointsAwarded = (int) ($config['points_awarded'] ?? $config['pointsAwarded'] ?? 0);

        if ($visitThreshold <= 0 || $pointsAwarded <= 0) {
            throw ValidationException::withMessages([
                'config' => ['visit_based rules require visit_threshold > 0 and points_awarded > 0.'],
            ]);
        }

        return [
            'visit_threshold' => $visitThreshold,
            'points_awarded' => $pointsAwarded,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validatePeriodSpending(array $config): array
    {
        $period = (string) ($config['period'] ?? '');
        $minimumSpend = (float) ($config['minimum_spend'] ?? $config['minimumSpend'] ?? 0);
        $rewardPercent = (float) (
            $config['reward_percent']
            ?? $config['rewardPercent']
            ?? $config['percentage']
            ?? 0
        );

        if (! in_array($period, ['monthly', 'weekly', 'yearly'], true)) {
            throw ValidationException::withMessages([
                'config' => ['period_spending rules require period of monthly, weekly, or yearly.'],
            ]);
        }

        if ($minimumSpend < 0) {
            throw ValidationException::withMessages([
                'config' => ['period_spending rules require minimum_spend >= 0.'],
            ]);
        }

        if ($rewardPercent <= 0) {
            throw ValidationException::withMessages([
                'config' => ['period_spending rules require reward_percent > 0.'],
            ]);
        }

        return [
            'period' => $period,
            'minimum_spend' => $minimumSpend,
            'reward_percent' => $rewardPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function validatePercentageReward(array $config): array
    {
        $percentage = (float) ($config['percentage'] ?? 0);
        if ($percentage <= 0) {
            throw ValidationException::withMessages([
                'config' => ['percentage_reward rules require percentage > 0.'],
            ]);
        }

        return ['percentage' => $percentage];
    }
}
