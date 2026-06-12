<?php

namespace App\Modules\Inventory\Support;

final class StockEnforcementMode
{
    public const STRICT = 'strict';

    public const WARNING = 'warning';

    public const DEFERRED = 'deferred';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::STRICT, self::WARNING, self::DEFERRED];
    }

    public static function normalize(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        if (in_array($mode, self::all(), true)) {
            return $mode;
        }

        return self::DEFERRED;
    }

    public static function fromLegacyBoolean(bool $enforce): string
    {
        return $enforce ? self::STRICT : self::WARNING;
    }
}
