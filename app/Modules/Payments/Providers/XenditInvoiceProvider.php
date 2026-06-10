<?php

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use App\Modules\Payments\Support\PaymentEnvironment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Xendit Invoice API v2 integration (hosted checkout, QRIS-capable when enabled on the Xendit account).
 *
 * @see https://developers.xendit.co/api-reference/#create-invoice
 */
final class XenditInvoiceProvider implements PaymentProviderInterface
{
    private const DEFAULT_API_BASE = 'https://api.xendit.co';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config = [],
    ) {}

    /** @inheritdoc */
    public function createTransaction(array $payload): array
    {
        $secret = trim((string) ($this->config['secret_key'] ?? ''));
        if ($secret === '') {
            if (! PaymentEnvironment::allowsStubMode()) {
                throw ValidationException::withMessages([
                    'gateway' => ['Payment provider configuration is invalid.'],
                ]);
            }

            return $this->stubCreate($payload);
        }

        $base = rtrim((string) ($this->config['api_base_url'] ?? self::DEFAULT_API_BASE), '/');
        $externalId = (string) ($payload['externalReference'] ?? '');
        if ($externalId === '') {
            throw ValidationException::withMessages(['externalReference' => ['externalReference is required for Xendit invoices.']]);
        }

        $orderId = (string) ($payload['orderId'] ?? '');
        $description = (string) ($this->config['invoice_description'] ?? 'Restaurant order payment');
        if ($orderId !== '') {
            $description .= ' #'.$orderId;
        }

        $body = [
            'external_id' => $externalId,
            'amount' => (float) $payload['amount'],
            'currency' => strtoupper((string) ($payload['currency'] ?? 'IDR')),
            'description' => $description,
            'invoice_duration' => (int) ($this->config['invoice_duration_seconds'] ?? 1800),
            'payer_email' => (string) ($this->config['payer_email'] ?? 'checkout@invoice.local'),
        ];

        $methods = $this->config['payment_methods'] ?? null;
        if (is_array($methods) && $methods !== []) {
            $body['payment_methods'] = array_values(array_filter(array_map('strval', $methods)));
        }

        $successUrl = $this->config['success_redirect_url'] ?? null;
        $failureUrl = $this->config['failure_redirect_url'] ?? null;
        if (is_string($successUrl) && trim($successUrl) !== '') {
            $body['success_redirect_url'] = trim($successUrl);
        }
        if (is_string($failureUrl) && trim($failureUrl) !== '') {
            $body['failure_redirect_url'] = trim($failureUrl);
        }

        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post($base.'/v2/invoices', $body);

        if ($response->successful()) {
            /** @var array<string, mixed> $json */
            $json = is_array($response->json()) ? $response->json() : [];

            return $this->mapInvoiceResponseToProviderShape($json, $payload);
        }

        if (in_array($response->status(), [400, 409], true)) {
            $existing = $this->fetchInvoiceByExternalId($secret, $base, $externalId);
            if ($existing !== null) {
                return $this->mapInvoiceResponseToProviderShape($existing, $payload);
            }
        }

        Log::warning('Xendit invoice creation failed.', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 2000),
            'external_id' => $externalId,
        ]);

        throw ValidationException::withMessages([
            'gateway' => ['Xendit invoice creation failed (HTTP '.$response->status().').'],
        ]);
    }

    /** @inheritdoc */
    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool
    {
        $expected = (string) ($this->config['webhook_token'] ?? '');
        $incoming = (string) ($headers['x-callback-token'] ?? '');

        if ($expected === '' || $incoming === '') {
            return false;
        }

        return hash_equals($expected, $incoming);
    }

    /** @inheritdoc */
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
        $invoice = $this->fetchInvoiceByExternalId($secret, $base, $externalReference);
        if ($invoice === null) {
            return [
                'externalReference' => $externalReference,
                'status' => 'pending',
                'payload' => ['provider' => 'xendit', 'mode' => 'missing_remote'],
            ];
        }

        return [
            'externalReference' => $externalReference,
            'status' => $this->mapRemoteInvoiceStatus((string) ($invoice['status'] ?? 'PENDING')),
            'payload' => $invoice,
        ];
    }

    /** @inheritdoc */
    public function expireOrCancelPayment(string $externalReference): array
    {
        $secret = trim((string) ($this->config['secret_key'] ?? ''));
        if ($secret === '') {
            return [
                'externalReference' => $externalReference,
                'status' => 'expired',
                'payload' => ['provider' => 'xendit', 'mode' => 'stub'],
            ];
        }

        $base = rtrim((string) ($this->config['api_base_url'] ?? self::DEFAULT_API_BASE), '/');
        $invoice = $this->fetchInvoiceByExternalId($secret, $base, $externalReference);
        if ($invoice === null) {
            return [
                'externalReference' => $externalReference,
                'status' => 'expired',
                'payload' => ['provider' => 'xendit', 'mode' => 'expire_skipped_missing'],
            ];
        }

        $invoiceId = (string) ($invoice['id'] ?? '');
        if ($invoiceId === '') {
            return [
                'externalReference' => $externalReference,
                'status' => 'expired',
                'payload' => ['provider' => 'xendit', 'mode' => 'expire_skipped_no_id'],
            ];
        }

        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->post($base.'/v2/invoices/'.$invoiceId.'/expire!');

        if (! $response->successful()) {
            Log::info('Xendit invoice expire call returned non-success (invoice may already be terminal).', [
                'status' => $response->status(),
                'invoice_id' => $invoiceId,
            ]);
        }

        return [
            'externalReference' => $externalReference,
            'status' => 'expired',
            'payload' => is_array($response->json()) ? $response->json() : ['http_status' => $response->status()],
        ];
    }

    /** @inheritdoc */
    public function reconcileTransaction(string $externalReference, array $context = []): array
    {
        return $this->fetchRemoteStatus($externalReference);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stubCreate(array $payload): array
    {
        $method = strtolower((string) ($payload['paymentMethod'] ?? 'ewallet'));
        $externalReference = (string) ($payload['externalReference'] ?? ('xdt-'.uniqid()));
        $expiry = now()->addMinutes(30)->toIso8601String();

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
                'configured' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapInvoiceResponseToProviderShape(array $json, array $payload): array
    {
        $externalReference = (string) ($json['external_id'] ?? $payload['externalReference'] ?? '');
        $method = strtolower((string) ($payload['paymentMethod'] ?? 'qris'));
        $expiry = $json['expiry_date'] ?? $json['expires_at'] ?? null;
        $expiryIso = is_string($expiry) && trim($expiry) !== '' ? $expiry : now()->addMinutes(30)->toIso8601String();

        $qrString = null;
        if (isset($json['available_qr_codes']) && is_array($json['available_qr_codes'])) {
            foreach ($json['available_qr_codes'] as $row) {
                if (is_array($row) && isset($row['qr_string']) && is_string($row['qr_string']) && $row['qr_string'] !== '') {
                    $qrString = $row['qr_string'];
                    break;
                }
            }
        }

        $checkoutUrl = isset($json['invoice_url']) && is_string($json['invoice_url']) ? $json['invoice_url'] : null;

        return [
            'externalReference' => $externalReference,
            'paymentMethod' => $method,
            'status' => $this->mapRemoteInvoiceStatus((string) ($json['status'] ?? 'PENDING')),
            'checkout_url' => $checkoutUrl,
            'qr_string' => $qrString,
            'deeplink_url' => null,
            'va_number' => null,
            'expiry_time' => $expiryIso,
            'expires_at' => $expiryIso,
            'provider_metadata' => [
                'provider' => 'xendit',
                'channel' => $method,
                'invoice_id' => $json['id'] ?? null,
            ],
            'raw' => array_merge($json, [
                'provider' => 'xendit',
                'stub' => false,
                'configured' => true,
            ]),
        ];
    }

    private function mapRemoteInvoiceStatus(string $status): string
    {
        $u = strtoupper(trim($status));

        return match ($u) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED', 'REJECTED' => 'failed',
            'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * @return ?array<string, mixed>
     */
    private function fetchInvoiceByExternalId(string $secret, string $base, string $externalId): ?array
    {
        $response = Http::withBasicAuth($secret, '')
            ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
            ->acceptJson()
            ->get($base.'/v2/invoices', [
                'external_id' => $externalId,
                'limit' => 1,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (isset($json['data']) && is_array($json['data']) && isset($json['data'][0]) && is_array($json['data'][0])) {
            return $json['data'][0];
        }

        if (is_array($json) && isset($json[0]) && is_array($json[0])) {
            return $json[0];
        }

        return null;
    }
}
