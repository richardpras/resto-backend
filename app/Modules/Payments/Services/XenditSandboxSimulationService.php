<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Payments\Domain\PaymentTransactionEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class XenditSandboxSimulationService
{
    /**
     * @return array{transaction: PaymentTransaction, providerResponseStatus: int, providerResponseBody: string}
     */
    public function simulateProviderPaid(int $paymentId): array
    {
        $currentEnv = strtolower((string) config('app.env', app()->environment()));
        if (! in_array($currentEnv, ['local', 'testing', 'sandbox'], true)) {
            throw ValidationException::withMessages([
                'environment' => ['Provider simulation is only available in local/testing/sandbox environments.'],
            ]);
        }

        $tx = PaymentTransaction::query()->find($paymentId);
        if ($tx === null) {
            throw ValidationException::withMessages([
                'paymentId' => ['Payment transaction not found.'],
            ]);
        }

        if ((string) $tx->provider !== 'xendit') {
            throw ValidationException::withMessages([
                'paymentId' => ['Only Xendit transactions can use provider simulation.'],
            ]);
        }

        if ((string) $tx->status !== 'pending') {
            throw ValidationException::withMessages([
                'paymentId' => ['Only pending transactions can be provider-simulated.'],
            ]);
        }

        $externalId = trim((string) $tx->external_reference);
        if ($externalId === '') {
            throw ValidationException::withMessages([
                'externalReference' => ['Transaction has no external reference for Xendit simulation.'],
            ]);
        }

        $secret = trim((string) config('payments.providers.xendit.secret_key', ''));
        if ($secret === '') {
            throw ValidationException::withMessages([
                'xendit' => ['XENDIT_SECRET_KEY is required for provider simulation.'],
            ]);
        }

        $amount = (int) round((float) $tx->amount);
        if ($amount < 1500) {
            throw ValidationException::withMessages([
                'amount' => ['Xendit QR simulate minimum amount is 1500 IDR.'],
            ]);
        }

        $base = rtrim((string) config('payments.providers.xendit.api_base_url', 'https://api.xendit.co'), '/');
        $endpoint = $base.'/qr_codes/'.rawurlencode($externalId).'/payments/simulate';
        $requestBody = ['amount' => $amount];

        $this->recordEvent((int) $tx->id, 'sandbox_provider_simulation_requested', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
            'endpoint' => $endpoint,
            'amount' => $amount,
        ]);

        Log::info('Xendit provider sandbox simulation request.', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
            'transaction_id' => (int) $tx->id,
            'external_id' => $externalId,
            'endpoint' => $endpoint,
            'amount' => $amount,
        ]);

        try {
            $response = Http::withBasicAuth($secret, '')
                ->timeout((int) config('payments.providers.xendit.http_timeout_seconds', 30))
                ->retry(2, 300)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $requestBody);
        } catch (ConnectionException $exception) {
            $this->recordEvent((int) $tx->id, 'sandbox_provider_simulation_failed', [
                'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
                'reason' => 'connection_exception',
                'message' => mb_substr($exception->getMessage(), 0, 600),
            ]);
            throw ValidationException::withMessages([
                'provider' => ['Xendit provider simulation request timed out.'],
            ]);
        }

        $body = mb_substr((string) $response->body(), 0, 4000);
        Log::info('Xendit provider sandbox simulation response.', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
            'transaction_id' => (int) $tx->id,
            'external_id' => $externalId,
            'status' => $response->status(),
            'body' => $body,
        ]);

        if (! $response->successful()) {
            $this->recordEvent((int) $tx->id, 'sandbox_provider_simulation_failed', [
                'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
                'status' => $response->status(),
                'body' => $body,
            ]);

            throw ValidationException::withMessages([
                'provider' => ['Xendit provider simulation failed (HTTP '.$response->status().').'],
            ]);
        }

        $this->recordEvent((int) $tx->id, 'sandbox_provider_simulation_dispatched', [
            'marker' => 'PAYMENT-XENDIT-SANDBOX-PROVIDER-SIMULATE-01',
            'status' => $response->status(),
        ]);

        return [
            'transaction' => $tx->fresh() ?? $tx,
            'providerResponseStatus' => $response->status(),
            'providerResponseBody' => $body,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $transactionId, string $eventType, array $payload): void
    {
        PaymentTransactionEvent::query()->create([
            'payment_transaction_id' => $transactionId,
            'event_type' => $eventType,
            'event_idempotency_key' => null,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}

