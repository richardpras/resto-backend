<?php

namespace App\Modules\Inventory\Support;

final class DeferredConsumptionTrigger
{
    public const SHIFT_CLOSE = 'shift_close';

    public const DAILY_STOCKTAKE = 'daily_stocktake';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SHIFT_CLOSE, self::DAILY_STOCKTAKE];
    }

    public static function normalize(string $trigger): string
    {
        $normalized = strtolower(trim($trigger));

        return in_array($normalized, self::all(), true)
            ? $normalized
            : self::SHIFT_CLOSE;
    }
}
