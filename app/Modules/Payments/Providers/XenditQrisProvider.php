<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use App\Modules\Payments\Support\PaymentEnvironment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Direct QRIS-first Xendit adapter for POS flows.
 * - QRIS method => POST /qr_codes (dynamic QR string returned directly)
 * - Other methods => fallback to invoice flow
 */
final class XenditQrisProvider implements PaymentProviderInterface
{
    private const DEFAULT_API_BASE = 'https://api.xendit.co';

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function createTransaction(array $payload): array
    {
        $method = strtolower((string) ($payload['paymentMethod'] ?? 'qris'));
        if ($method === 'qris') {
            return $this->createDirectQrisTransaction($payload);
        }

        return (new XenditInvoiceProvider($this->config))->createTransaction($payload);
    }

    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool
    {
        $expected = (string) ($this->config['webhook_token'] ?? '');
        $incoming = (string) ($headers['x-callback-token'] ?? '');

        return $expected !== '' && $incoming !== '' && hash_equals($expected, $incoming);
    }

    public function fetchRemoteStatus(string $externalReference): array
    {
        $secret = trim((string) ($this->config['secret_key'] ?? ''));
        if ($secret === '') {
            return [
                'externalReference' => $externalReference,
                'status' => 'pending',
                'payload' => ['provider' => 'xendit', 'mode' => 'stub'],
            ];
        }

        $base = rtrim((string) ($this->config['api_base_url'] ?? self::DEFAULT_API_BASE), '/');
        $qr = $this->fetchQrByExternalId($secret, $base, $externalReference);
        if ($qr === null) {
            return [
                'externalReference' => $externalReference,
                'status' => 'pending',
                'payload' => ['provider' => 'xendit', 'mode' => 'missing_remote_qr'],
            ];
        }

        $payment = $this->fetchLatestQrisPayment($secret, $base, $externalReference);
        $status = $payment !== null
            ? $this->mapRemoteQrisPaymentStatus((string) ($payment['status'] ?? 'PENDING'))
            : 'pending';

        return [
            'externalReference' => $externalReference,
            'status' => $status,
            'payload' => ['qr' => $qr, 'payment' => $payment, 'provider' => 'xendit'],
        ];
    }

    public function expireOrCancelPayment(string $externalReference): array
    {
        return [
            'externalReference' => $externalReference,
            'status' => 'expired',
            'payload' => ['provider' => 'xendit', 'mode' => 'qris_direct_expire_local'],
        ];
    }

    public function reconcileTransaction(string $externalReference, array $context = []): array
    {
        return $this->fetchRemoteStatus($externalReference);
    }

    /** @param array<string,mixed> $payload */
    private function createDirectQrisTransaction(array $payload): array
    {
        $externalId = trim((string) ($payload['externalReference'] ?? ''));
        if ($externalId === '') {
            throw ValidationException::withMessages(['externalReference' => ['externalReference is required for Xendit QRIS.']]);
        }

        $expiryMinutes = max(1, (int) ($this->config['qris_expiry_minutes'] ?? 15));
        $expiry = now()->addMinutes($expiryMinutes)->toIso8601String();
        $secret = trim((string) ($this->config['secret_key'] ?? ''));

        if ($secret === '') {
            if (! PaymentEnvironment::allowsStubMode()) {
                throw ValidationException::withMessages([
                    'gateway' => ['Payment provider configuration is invalid.'],
                ]);
            }

            return [
                'externalReference' => $externalId,
                'paymentMethod' => 'qris',
                'status' => 'pending',
                'checkout_url' => null,
                'qr_string' => 'XENDIT-QRIS:'.$externalId,
                'deeplink_url' => null,
                'va_number' => null,
                'expiry_time' => $expiry,
                'expires_at' => $expiry,
                'provider_metadata' => [
                    'provider' => 'xendit',
                    'channel' => 'qris',
                    'mode' => 'direct_qris_stub',
                ],
                'raw' => [
                    'provider' => 'xendit',
                    'stub' => true,
                    'configured' => false,
                ],
            ];
        }

        $base = rtrim((string) ($this->config['api_base_url'] ?? self::DEFAULT_API_BASE), '/');
        $callbackUrl = (string) ($this->config['qris_callback_url'] ?? '');
        if (trim($callbackUrl) === '') {
            throw ValidationException::withMessages([
                'gateway' => ['Xendit QRIS callback URL is not configured. Set XENDIT_QRIS_CALLBACK_URL.'],
            ]);
        }

        $body = [
            'external_id' => $externalId,
            'type' => 'DYNAMIC',
            'callback_url' => trim($callbackUrl),
            'amount' => (float) ($payload['amount'] ?? 0),
            'metadata' => [
                'order_id' => (string) ($payload['orderId'] ?? ''),
                'outlet_id' => (string) ($payload['outletId'] ?? ''),
                'currency' => strtoupper((string) ($payload['currency'] ?? 'IDR')),
            ],
        ];
        Log::info('Xendit QRIS outbound request.', [
            'endpoint' => $base.'/qr_codes',
            'external_id' => $externalId,
            'payment_method' => 'qris',
            'payload' => $body,
        ]);

        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post($base.'/qr_codes', $body);
        Log::info('Xendit QRIS response.', [
            'endpoint' => $base.'/qr_codes',
            'external_id' => $externalId,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 4000),
        ]);

        if ($response->successful()) {
            /** @var array<string,mixed> $json */
            $json = is_array($response->json()) ? $response->json() : [];
            return $this->mapQrisCreateResponse($json, $externalId, $expiry);
        }

        if (in_array($response->status(), [400, 409], true)) {
            $existing = $this->fetchQrByExternalId($secret, $base, $externalId);
            if ($existing !== null) {
                return $this->mapQrisCreateResponse($existing, $externalId, $expiry);
            }
        }

        Log::warning('Xendit QRIS creation failed.', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 2000),
            'external_id' => $externalId,
        ]);

        throw ValidationException::withMessages([
            'gateway' => ['Xendit QRIS creation failed (HTTP '.$response->status().').'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mapQrisCreateResponse(array $json, string $externalId, string $fallbackExpiry): array
    {
        $expiry = is_string($json['updated'] ?? null) ? (string) $json['updated'] : $fallbackExpiry;

        return [
            'externalReference' => (string) ($json['external_id'] ?? $externalId),
            'paymentMethod' => 'qris',
            'status' => 'pending',
            'checkout_url' => null,
            'qr_string' => (string) ($json['qr_string'] ?? ''),
            'deeplink_url' => null,
            'va_number' => null,
            'expiry_time' => $expiry,
            'expires_at' => $expiry,
            'provider_metadata' => [
                'provider' => 'xendit',
                'channel' => 'qris',
                'mode' => 'direct_qris',
                'qr_id' => $json['id'] ?? null,
                'qr_status' => $json['status'] ?? null,
            ],
            'raw' => array_merge($json, [
                'provider' => 'xendit',
                'mode' => 'direct_qris',
                'configured' => true,
            ]),
        ];
    }

    /**
     * @return ?array<string,mixed>
     */
    private function fetchQrByExternalId(string $secret, string $base, string $externalId): ?array
    {
        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->get($base.'/qr_codes/'.rawurlencode($externalId));

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        return is_array($json) ? $json : null;
    }

    /**
     * @return ?array<string,mixed>
     */
    private function fetchLatestQrisPayment(string $secret, string $base, string $externalId): ?array
    {
        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->get($base.'/qr_codes/'.rawurlencode($externalId).'/payments');

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (is_array($json) && isset($json[0]) && is_array($json[0])) {
            return $json[0];
        }

        if (is_array($json) && isset($json['data']) && is_array($json['data']) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return $json['data'][0];
        }

        return null;
    }

    private function mapRemoteQrisPaymentStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'COMPLETED', 'PAID' => 'paid',
            'FAILED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }
}

