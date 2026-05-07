<?php

namespace App\Modules\Payments\Services\Providers;

interface PaymentProviderInterface
{
    /** @param array<string,mixed> $payload */
    public function createTransaction(array $payload): array;

    /** @param array<string,mixed> $payload @param array<string,string> $headers */
    public function verifyWebhookSignature(array $payload, array $headers, string $rawBody): bool;

    public function fetchRemoteStatus(string $externalReference): array;

    public function expireOrCancelPayment(string $externalReference): array;

    /** @param array<string,mixed> $context */
    public function reconcileTransaction(string $externalReference, array $context = []): array;
}
