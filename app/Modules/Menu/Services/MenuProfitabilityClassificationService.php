<?php

namespace App\Modules\Menu\Services;

final class MenuProfitabilityClassificationService
{
    public const LOSS = 'LOSS';
    public const LOW = 'LOW';
    public const MEDIUM = 'MEDIUM';
    public const HIGH = 'HIGH';
    public const PREMIUM = 'PREMIUM';

    public function classify(float $marginPercent): string
    {
        if ($marginPercent < 0) {
            return self::LOSS;
        }
        if ($marginPercent < 20) {
            return self::LOW;
        }
        if ($marginPercent < 40) {
            return self::MEDIUM;
        }
        if ($marginPercent < 60) {
            return self::HIGH;
        }

        return self::PREMIUM;
    }
}
