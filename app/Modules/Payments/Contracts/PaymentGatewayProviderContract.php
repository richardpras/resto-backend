<?php

namespace App\Modules\Payments\Contracts;

/**
 * Gateway adapter contract. Implementations are invoked by {@see \App\Modules\Payments\Services\PaymentGatewayService}.
 *
 * @phpstan-type ProviderTransactionResult array{
 *   externalReference?: string,
 *   paymentMethod?: string|null,
 *   status?: string,
 *   checkout_url?: string|null,
 *   qr_string?: string|null,
 *   deeplink_url?: string|null,
 *   va_number?: string|null,
 *   expiry_time?: string|null,
 *   expires_at?: string|null,
 *   provider_metadata?: array<string, mixed>|null,
 *   raw?: array<string, mixed>|null
 * }
 */
interface PaymentGatewayProviderContract
{
    /**
     * @param  array<string, mixed>  $payload
     * @return ProviderTransactionResult
     */
    public function createTransaction(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool;

    /**
     * @return array{externalReference: string, status: string, payload?: array<string, mixed>}
     */
    public function fetchRemoteStatus(string $externalReference): array;

    /**
     * @return array{externalReference: string, status: string, payload?: array<string, mixed>}
     */
    public function expireOrCancelPayment(string $externalReference): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array{externalReference: string, status: string, payload?: array<string, mixed>}
     */
    public function reconcileTransaction(string $externalReference, array $context = []): array;
}
