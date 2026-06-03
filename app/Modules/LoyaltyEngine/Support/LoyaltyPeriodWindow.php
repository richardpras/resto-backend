<?php

namespace App\Modules\LoyaltyEngine\Support;

use Carbon\Carbon;
use InvalidArgumentException;

class LoyaltyPeriodWindow
{
    /**
     * @return array{start: Carbon, end: Carbon, key: string}
     */
    public static function forPeriod(string $period, Carbon $asOf): array
    {
        return match ($period) {
            'monthly' => [
                'start' => $asOf->copy()->startOfMonth(),
                'end' => $asOf->copy()->endOfMonth(),
                'key' => 'monthly:'.$asOf->format('Y-m'),
            ],
            'weekly' => [
                'start' => $asOf->copy()->startOfWeek(Carbon::MONDAY),
                'end' => $asOf->copy()->endOfWeek(Carbon::SUNDAY),
                'key' => 'weekly:'.$asOf->isoFormat('GGGG-[W]WW'),
            ],
            'yearly' => [
                'start' => $asOf->copy()->startOfYear(),
                'end' => $asOf->copy()->endOfYear(),
                'key' => 'yearly:'.$asOf->format('Y'),
            ],
            default => throw new InvalidArgumentException('Unsupported loyalty period: '.$period),
        };
    }
}
