<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Exceptions\PaymentConfigurationException;
use App\Modules\Payments\Registry\PaymentGatewayRegistry;
use App\Modules\Payments\Support\PaymentEnvironment;
use Illuminate\Validation\ValidationException;

final class PaymentConfigurationHealthService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly GatewayProviderResolutionService $gatewayProviderResolutionService,
    ) {}

    /** @return array<string, mixed> */
    public function report(?string $provider = null, ?int $outletId = null): array
    {
        $resolved = $this->resolveProviderKey($provider, $outletId);
        $assessment = $this->assessProvider($resolved);

        return [
            'provider' => $resolved,
            'healthy' => $assessment['healthy'],
            'status' => $assessment['status'],
            'mode' => PaymentEnvironment::allowsStubMode() ? 'development' : 'production',
            'stubAllowed' => PaymentEnvironment::allowsStubMode(),
            'wouldUseStub' => $assessment['wouldUseStub'],
            'missing' => $assessment['missing'],
            'warnings' => $assessment['warnings'],
        ];
    }

    /** @return array<string, mixed> */
    public function assessProvider(string $provider): array
    {
        $normalized = strtolower(trim($provider));
        $config = $this->paymentGatewayRegistry->definition($normalized);
        if (! is_array($config)) {
            return [
                'healthy' => false,
                'status' => 'critical',
                'wouldUseStub' => true,
                'missing' => ['PROVIDER_NOT_REGISTERED'],
                'warnings' => [],
            ];
        }

        $missing = [];
        $warnings = [];
        $wouldUseStub = $this->wouldOperateInStubMode($normalized, $config);

        $stubAllowed = PaymentEnvironment::allowsStubMode();

        foreach ($this->requiredCredentialChecks($normalized, $config) as $check) {
            if ($check['present']) {
                continue;
            }
            if ($stubAllowed && $wouldUseStub) {
                $warnings[] = $check['key'];
            } elseif ($check['severity'] === 'critical') {
                $missing[] = $check['key'];
            } else {
                $warnings[] = $check['key'];
            }
        }

        if ($wouldUseStub && ! $stubAllowed) {
            $missing[] = 'PRODUCTION_STUB_FORBIDDEN';
        }

        $status = 'healthy';
        if ($missing !== []) {
            $status = 'critical';
        } elseif ($warnings !== []) {
            $status = 'warning';
        }

        return [
            'healthy' => $status === 'healthy',
            'status' => $status,
            'wouldUseStub' => $wouldUseStub,
            'missing' => array_values(array_unique($missing)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function assertHealthyForInitiation(string $provider, ?int $outletId = null): void
    {
        $resolved = $this->resolveProviderKey($provider, $outletId);
        $assessment = $this->assessProvider($resolved);

        if ($assessment['status'] !== 'critical') {
            return;
        }

        throw ValidationException::withMessages([
            'gateway' => ['Payment provider configuration is invalid.'],
            'provider' => [$resolved],
            'missing' => $assessment['missing'],
            'warnings' => $assessment['warnings'],
        ]);
    }

    public function assertProductionBootReady(): void
    {
        if (! PaymentEnvironment::isProduction()) {
            return;
        }

        if (! (bool) config('payments.strict_production_boot', true)) {
            return;
        }

        $defaultProvider = strtolower(trim((string) config('payments.default_provider', 'midtrans')));
        $assessment = $this->assessProvider($defaultProvider);
        if ($assessment['status'] !== 'critical') {
            return;
        }

        throw new PaymentConfigurationException(
            'Payment provider configured but credentials are missing.',
            $defaultProvider,
            $assessment['missing'],
        );
    }

    /** @param array<string, mixed> $config */
    public function wouldOperateInStubMode(string $provider, ?array $config = null): bool
    {
        $normalized = strtolower(trim($provider));
        $config ??= $this->paymentGatewayRegistry->definition($normalized);
        if (! is_array($config)) {
            return true;
        }

        return match ($normalized) {
            'xendit' => trim((string) ($config['secret_key'] ?? '')) === '',
            'midtrans', 'manual' => true,
            default => true,
        };
    }

    private function resolveProviderKey(?string $provider, ?int $outletId): string
    {
        if (is_string($provider) && trim($provider) !== '') {
            return strtolower(trim($provider));
        }

        $outlet = $outletId !== null && $outletId > 0 ? $outletId : 0;

        return $this->gatewayProviderResolutionService->resolve($outlet, null);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{key:string,present:bool,severity:string}>
     */
    private function requiredCredentialChecks(string $provider, array $config): array
    {
        return match (strtolower($provider)) {
            'xendit' => [
                $this->credentialCheck('XENDIT_SECRET_KEY', (string) ($config['secret_key'] ?? ''), 'critical'),
                $this->credentialCheck('XENDIT_WEBHOOK_TOKEN', (string) ($config['webhook_token'] ?? ''), 'critical'),
                $this->credentialCheck('XENDIT_QRIS_CALLBACK_URL', (string) ($config['qris_callback_url'] ?? ''), 'critical'),
            ],
            'midtrans', 'manual' => [
                $this->credentialCheck('MIDTRANS_SERVER_KEY', (string) ($config['server_key'] ?? ''), 'critical'),
                $this->credentialCheck('MIDTRANS_CLIENT_KEY', (string) ($config['client_key'] ?? ''), 'critical'),
                $this->credentialCheck('MIDTRANS_WEBHOOK_SECRET', (string) ($config['webhook_secret'] ?? ''), 'critical'),
            ],
            default => [
                $this->credentialCheck('PROVIDER_NOT_REGISTERED', false, 'critical'),
            ],
        };
    }

    /** @return array{key:string,present:bool,severity:string} */
    private function credentialCheck(string $key, string|bool $value, string $severity): array
    {
        $present = is_bool($value) ? $value : trim($value) !== '';

        return ['key' => $key, 'present' => $present, 'severity' => $severity];
    }
}
