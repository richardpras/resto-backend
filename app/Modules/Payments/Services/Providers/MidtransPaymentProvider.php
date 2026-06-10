<?php

namespace App\Modules\Payments\Services\Providers;

use App\Modules\Payments\Support\PaymentEnvironment;
use Illuminate\Validation\ValidationException;

class MidtransPaymentProvider implements PaymentProviderInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function createTransaction(array $payload): array
    {
        if (! PaymentEnvironment::allowsStubMode()) {
            throw ValidationException::withMessages([
                'gateway' => ['Payment provider configuration is invalid.'],
            ]);
        }

        $method = strtolower((string) ($payload['paymentMethod'] ?? 'qris'));
        $externalReference = (string) ($payload['externalReference'] ?? ('mid-'.uniqid()));
        $expiry = now()->addMinutes(30)->toISOString();

        return [
            'externalReference' => $externalReference,
            'paymentMethod' => $method,
            'status' => 'pending',
            'checkout_url' => $method !== 'bank_transfer' ? 'https://pay.midtrans.local/checkout/'.$externalReference : null,
            'qr_string' => in_array($method, ['qris', 'cashless'], true) ? 'QRIS:'.$externalReference : null,
            'deeplink_url' => $method === 'ewallet' ? 'gojek://gopay/pay?ref='.$externalReference : null,
            'va_number' => $method === 'bank_transfer' ? '8808'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT) : null,
            'expiry_time' => $expiry,
            'expires_at' => $expiry,
            'provider_metadata' => [
                'provider' => 'midtrans',
                'channel' => $method,
            ],
            'raw' => [
                'provider' => 'midtrans',
                'is_production' => (bool) ($this->config['is_production'] ?? false),
                'snap_url' => (string) ($this->config['snap_url'] ?? ''),
            ],
        ];
    }

    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool
    {
        $incomingSignature = (string) ($headers['x-signature'] ?? $headers['x-callback-signature'] ?? '');
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($secret === '' || $incomingSignature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $incomingSignature);
    }

    public function fetchRemoteStatus(string $externalReference): array
    {
        return [
            'externalReference' => $externalReference,
            'status' => 'pending',
            'payload' => ['provider' => 'midtrans', 'mode' => 'stub'],
        ];
    }

    public function expireOrCancelPayment(string $externalReference): array
    {
        return [
            'externalReference' => $externalReference,
            'status' => 'expired',
            'payload' => ['provider' => 'midtrans', 'action' => 'expire'],
        ];
    }

    public function reconcileTransaction(string $externalReference, array $context = []): array
    {
        return $this->fetchRemoteStatus($externalReference);
    }
}
