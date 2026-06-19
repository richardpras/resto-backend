<?php

namespace App\Modules\LoyaltyEngine\Support;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;

class LoyaltyRuleSummaryFormatter
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public static function format(?string $programType, ?array $config): ?string
    {
        if ($config === null || $config === []) {
            return null;
        }

        return match ($programType) {
            LoyaltyProgram::TYPE_SPEND_BASED => self::formatSpendBased($config),
            LoyaltyProgram::TYPE_VISIT_BASED => self::formatVisitBased($config),
            LoyaltyProgram::TYPE_PERIOD_SPENDING => self::formatPeriodSpending($config),
            LoyaltyProgram::TYPE_PERCENTAGE_REWARD => self::formatPercentageReward($config),
            LoyaltyProgram::TYPE_MANUAL => 'Manual accrual',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function formatSpendBased(array $config): string
    {
        $points = (int) ($config['pointsEarned'] ?? 0);
        $amount = (float) ($config['earnPerAmount'] ?? 0);

        return sprintf('%d pt / Rp %s', $points, number_format($amount, 0, ',', '.'));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function formatVisitBased(array $config): string
    {
        $visits = (int) ($config['visit_threshold'] ?? 0);
        $points = (int) ($config['points_awarded'] ?? 0);

        return sprintf('%d pt every %d visits', $points, $visits);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function formatPeriodSpending(array $config): string
    {
        $period = (string) ($config['period'] ?? 'monthly');
        $percent = (float) ($config['reward_percent'] ?? 0);
        $minimum = (float) ($config['minimum_spend'] ?? 0);

        if ($minimum > 0) {
            return sprintf('%.1f%% on %s spend (min Rp %s)', $percent, $period, number_format($minimum, 0, ',', '.'));
        }

        return sprintf('%.1f%% on %s spend', $percent, $period);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function formatPercentageReward(array $config): string
    {
        $percent = (float) ($config['percentage'] ?? 0);

        return sprintf('%.1f%% reward', $percent);
    }
}
