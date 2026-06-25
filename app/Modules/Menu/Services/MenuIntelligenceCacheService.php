<?php

namespace App\Modules\Menu\Services;

use Illuminate\Support\Facades\Cache;

final class MenuIntelligenceCacheService
{
    public const PREFIX_DASHBOARD = 'menu_dashboard';

    public const PREFIX_FORECAST = 'menu_forecast_summary';

    public const PREFIX_OPTIMIZATION = 'menu_optimization_summary';

    public const PREFIX_ENGINEERING = 'menu_engineering_summary';

    public const PREFIX_AUTOMATION = 'menu_automation_summary';

    public const PREFIX_INTELLIGENCE_BUNDLE = 'menu_intelligence_bundle';

    public const TTL_DASHBOARD = 900;

    public const TTL_FORECAST = 1800;

    public const TTL_OPTIMIZATION = 1800;

    public const TTL_ENGINEERING = 1800;

    public const TTL_AUTOMATION = 300;

    /** @return array<int, string> */
    public function allPrefixes(): array
    {
        return [
            self::PREFIX_DASHBOARD,
            self::PREFIX_FORECAST,
            self::PREFIX_OPTIMIZATION,
            self::PREFIX_ENGINEERING,
            self::PREFIX_AUTOMATION,
            self::PREFIX_INTELLIGENCE_BUNDLE,
        ];
    }

    public function key(int $outletId, string $prefix, ?string $suffix = null): string
    {
        $base = "{$prefix}_{$outletId}";

        return $suffix !== null && $suffix !== '' ? "{$base}_{$suffix}" : $base;
    }

    public function has(int $outletId, string $prefix, ?string $suffix = null): bool
    {
        return Cache::has($this->key($outletId, $prefix, $suffix));
    }

    /** @param callable():mixed $callback */
    public function remember(int $outletId, string $prefix, int $ttlSeconds, callable $callback, ?string $suffix = null): mixed
    {
        return Cache::remember($this->key($outletId, $prefix, $suffix), $ttlSeconds, $callback);
    }

    public function forget(int $outletId, string $prefix, ?string $suffix = null): void
    {
        Cache::forget($this->key($outletId, $prefix, $suffix));
    }

    public function forgetOutlet(int $outletId): void
    {
        foreach ($this->allPrefixes() as $prefix) {
            $this->forget($outletId, $prefix);
        }
    }
}
