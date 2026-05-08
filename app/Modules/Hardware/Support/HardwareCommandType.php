<?php

namespace App\Modules\Hardware\Support;

final class HardwareCommandType
{
    public const PRINT_DOCUMENT = 'PRINT_DOCUMENT';
    public const OPEN_CASH_DRAWER = 'OPEN_CASH_DRAWER';
    public const TEST_PRINT = 'TEST_PRINT';
    public const PRINTER_STATUS_CHECK = 'PRINTER_STATUS_CHECK';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PRINT_DOCUMENT,
            self::OPEN_CASH_DRAWER,
            self::TEST_PRINT,
            self::PRINTER_STATUS_CHECK,
        ];
    }
}
