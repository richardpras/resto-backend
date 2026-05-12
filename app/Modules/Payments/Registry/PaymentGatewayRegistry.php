<?php

namespace App\Modules\Payments\Registry;

/**
 * Read-only view of configured gateway adapters and capability metadata.
 */
final class PaymentGatewayRegistry
{
    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $providerKey): ?array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('payments.providers', []);

        return $providers[$providerKey] ?? null;
    }

    public function adapterClass(string $providerKey): ?string
    {
        $def = $this->definition($providerKey);
        $class = $def['class'] ?? null;

        return is_string($class) && $class !== '' ? $class : null;
    }

    /**
     * @return array<string, bool|string|list<string>>
     */
    public function capabilities(string $providerKey): array
    {
        /** @var array<string, array<string, bool|string|list<string>>> $caps */
        $caps = config('payments.capabilities', []);

        return $caps[$providerKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function registeredProviderKeys(): array
    {
        /** @var array<string, mixed> $providers */
        $providers = config('payments.providers', []);

        return array_values(array_keys($providers));
    }
}
