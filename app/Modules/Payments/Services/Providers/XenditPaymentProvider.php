<?php

namespace App\Modules\Payments\Services\Providers;

class XenditPaymentProvider implements PaymentProviderInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function createTransaction(array $payload): array
    {
        $method = strtolower((string) ($payload['paymentMethod'] ?? 'ewallet'));
        $externalReference = (string) ($payload['externalReference'] ?? ('xdt-'.uniqid()));
        $expiry = now()->addMinutes(30)->toISOString();

        return [
            'externalReference' => $externalReference,
            'paymentMethod' => $method,
            'status' => 'pending',
            'checkout_url' => 'https://checkout.xendit.local/'.$externalReference,
            'qr_string' => $method === 'qris' ? 'XENDIT-QR:'.$externalReference : null,
            'deeplink_url' => $method === 'ewallet' ? 'xendit://pay/'.$externalReference : null,
            'va_number' => $method === 'bank_transfer' ? '9900'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT) : null,
            'expiry_time' => $expiry,
            'expires_at' => $expiry,
            'provider_metadata' => [
                'provider' => 'xendit',
                'channel' => $method,
            ],
            'raw' => [
                'provider' => 'xendit',
                'stub' => true,
                'configured' => (string) ($this->config['secret_key'] ?? '') !== '',
            ],
        ];
    }

    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool
    {
        $expected = (string) ($this->config['webhook_token'] ?? '');
        $incoming = (string) ($headers['x-callback-token'] ?? '');

        return $expected !== '' && hash_equals($expected, $incoming);
    }

    public function fetchRemoteStatus(string $externalReference): array
    {
        return [
            'externalReference' => $externalReference,
            'status' => 'pending',
            'payload' => ['provider' => 'xendit', 'mode' => 'stub'],
        ];
    }

    public function expireOrCancelPayment(string $externalReference): array
    {
        return [
            'externalReference' => $externalReference,
            'status' => 'expired',
            'payload' => ['provider' => 'xendit', 'action' => 'expire'],
        ];
    }

    public function reconcileTransaction(string $externalReference, array $context = []): array
    {
        return $this->fetchRemoteStatus($externalReference);
    }
}
