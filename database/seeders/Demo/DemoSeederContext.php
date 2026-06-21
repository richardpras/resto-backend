<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Support\Collection;

final class DemoSeederContext
{
    public static ?int $outletIdFilter = null;

    /** @var array<string, array{code: string, name: string, domain: string}> */
    public const OUTLET_SPECS = [
        'A' => ['code' => 'DEMO-SUNSET', 'name' => 'Sunset Cafe', 'domain' => 'sunset.demo.resto.local'],
        'B' => ['code' => 'DEMO-MOUNTAIN', 'name' => 'Mountain Cafe', 'domain' => 'mountain.demo.resto.local'],
    ];

    public static function baseTime(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse('2026-03-01 07:00:00');
    }

    /** @return Collection<int, Outlet> */
    public static function outlets(): Collection
    {
        $codes = array_column(self::OUTLET_SPECS, 'code');
        $query = Outlet::query()->whereIn('code', $codes)->orderBy('id');

        if (self::$outletIdFilter !== null && self::$outletIdFilter > 0) {
            $query->where('id', self::$outletIdFilter);
        }

        return $query->get();
    }

    public static function outletKeyFor(Outlet $outlet): ?string
    {
        foreach (self::OUTLET_SPECS as $key => $spec) {
            if ($spec['code'] === $outlet->code) {
                return $key;
            }
        }

        return null;
    }

    public static function specForKey(string $key): array
    {
        return self::OUTLET_SPECS[$key];
    }

    public static function reset(): void
    {
        self::$outletIdFilter = null;
    }
}
