<?php

namespace App\Modules\Inventory\Support;

final class InventoryCostingMethod
{
    public const MOVING_AVERAGE = 'moving_average';

    public const FIFO = 'fifo';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MOVING_AVERAGE, self::FIFO];
    }

    public static function normalize(string $method): string
    {
        $normalized = strtolower(trim($method));

        return in_array($normalized, self::all(), true)
            ? $normalized
            : self::MOVING_AVERAGE;
    }
}
