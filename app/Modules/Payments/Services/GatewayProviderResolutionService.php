<?php

namespace App\Modules\Payments\Services;

/**
 * Resolves which gateway provider key applies for an outlet, honoring explicit client choice
 * and optional per-outlet defaults from configuration.
 */
final class GatewayProviderResolutionService
{
    /**
     * @param  ?string  $requestedProvider  Non-empty client override (already normalized casing intent).
     */
    public function resolve(int $outletId, ?string $requestedProvider): string
    {
        if (is_string($requestedProvider) && trim($requestedProvider) !== '') {
            return strtolower(trim($requestedProvider));
        }

        return $this->defaultForOutlet($outletId);
    }

    public function defaultForOutlet(int $outletId): string
    {
        /** @var array<string|int, array<string, mixed>> $overrides */
        $overrides = config('payments.outlet_overrides', []);
        $fromOutlet = $overrides[$outletId] ?? $overrides[(string) $outletId] ?? null;
        if (is_array($fromOutlet)) {
            $p = $fromOutlet['default_provider'] ?? null;
            if (is_string($p) && trim($p) !== '') {
                return strtolower(trim($p));
            }
        }

        return strtolower(trim((string) config('payments.default_provider', 'midtrans')));
    }
}
