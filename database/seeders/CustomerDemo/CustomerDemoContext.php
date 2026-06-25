<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Carbon\CarbonImmutable;

final class CustomerDemoContext
{
    public const OUTLET_CODE = 'DEMO-WRWB';

    public const OUTLET_NAME = 'WR WB';

    public const EMAIL_DOMAIN = 'wrwb.demo';

    public const PERIOD_START = '2026-05-01';

    public const PERIOD_END = '2026-05-31';

    public const TENANT_ID = 1;

    /** @var array<string, array{email: string, name: string, role: string, pin: string}> */
    public const USER_SPECS = [
        'admin' => ['email' => 'admin@wrwb.demo', 'name' => 'Admin WR WB', 'role' => 'WR WB Admin', 'pin' => '0000'],
        'owner' => ['email' => 'owner@wrwb.demo', 'name' => 'Owner WR WB', 'role' => 'WR WB Owner', 'pin' => '1234'],
        'manager' => ['email' => 'manager@wrwb.demo', 'name' => 'Manager WR WB', 'role' => 'WR WB Manager', 'pin' => '2345'],
        'kasir1' => ['email' => 'kasir1@wrwb.demo', 'name' => 'Kasir A', 'role' => 'WR WB Cashier', 'pin' => '3456'],
        'kasir2' => ['email' => 'kasir2@wrwb.demo', 'name' => 'Kasir B', 'role' => 'WR WB Cashier', 'pin' => '4567'],
        'kitchen' => ['email' => 'kitchen@wrwb.demo', 'name' => 'Kitchen WR WB', 'role' => 'WR WB Kitchen', 'pin' => '5678'],
    ];

    public static ?Outlet $outlet = null;

    /** @var array<string, User> */
    public static array $users = [];

    public static ?int $warehouseId = null;

    public static ?int $supplierId = null;

    public static function date(int $day, int $hour = 12, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::parse(self::PERIOD_START)
            ->addDays(max(0, $day - 1))
            ->setTime($hour, $minute);
    }

    public static function outletId(): int
    {
        return (int) (self::$outlet?->id ?? 0);
    }

    public static function user(string $key): User
    {
        if (! isset(self::$users[$key])) {
            throw new \RuntimeException("Customer demo user [{$key}] is not seeded.");
        }

        return self::$users[$key];
    }

    public static function reset(): void
    {
        self::$outlet = null;
        self::$users = [];
        self::$warehouseId = null;
        self::$supplierId = null;
    }
}
