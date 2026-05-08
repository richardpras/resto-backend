<?php

namespace App\Modules\Payments\Services;

use App\Jobs\Payments\ProcessPaymentWebhookReceiptJob;
use App\Jobs\Payments\ReconcilePaymentTransactionJob;
use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Payments\Domain\PaymentTransactionEvent;
use App\Models\Modules\Payments\Domain\PaymentWebhookReceipt;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\GiftCards\Services\GiftCardSettlementHookService;
use App\Modules\Payments\Events\PaymentStatusChanged;
use App\Modules\Payments\Repositories\PaymentTransactionRepositoryInterface;
use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Support\Observability\AsyncOperationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentGatewayService
{
    public function __construct(
        private readonly PaymentTransactionRepositoryInterface $transactionRepository,
        private readonly JournalPostingService $journalPostingService,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly GiftCardSettlementHookService $giftCardSettlementHookService,
    ) {}

    /** @param array<string,mixed> $payload */
    public function initiateTransaction(User $user, array $payload): PaymentTransaction
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $outletId = (int) $payload['outletId'];
        if (! in_array($outletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $provider = strtolower(trim((string) ($payload['provider'] ?? config('payments.default_provider', 'midtrans'))));
        $idempotencyKey = trim((string) $payload['idempotencyKey']);
        $providerAdapter = $this->resolveProviderAdapter($provider);

        $existing = $this->transactionRepository->findByProviderAndIdempotency($provider, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($payload, $provider, $idempotencyKey, $outletId, $providerAdapter): PaymentTransaction {
            $order = Order::query()
                ->whereKey((int) $payload['orderId'])
                ->where('outlet_id', $outletId)
                ->first();
            if ($order === null) {
                throw (new ModelNotFoundException)->setModel(Order::class, [(string) $payload['orderId']]);
            }

            $splitId = isset($payload['orderSplitId']) ? (int) $payload['orderSplitId'] : null;
            if ($splitId !== null) {
                $splitExists = OrderSplit::query()
                    ->whereKey($splitId)
                    ->where('order_id', $order->id)
                    ->exists();
                if (! $splitExists) {
                    throw ValidationException::withMessages(['orderSplitId' => ['Referenced split does not belong to order.']]);
                }
            }

            $providerResponse = $providerAdapter->createTransaction($payload);
            $expiryTime = isset($providerResponse['expiry_time']) ? (string) $providerResponse['expiry_time'] : ($providerResponse['expires_at'] ?? ($payload['expiredAt'] ?? null));
            $externalReference = (string) ($providerResponse['externalReference'] ?? $payload['externalReference']);

            $transaction = $this->transactionRepository->create([
                'order_id' => (int) $order->id,
                'order_split_id' => $splitId,
                'outlet_id' => $outletId,
                'provider' => $provider,
                'external_reference' => $externalReference,
                'idempotency_key' => $idempotencyKey,
                'amount' => (float) $payload['amount'],
                'currency' => strtoupper((string) ($payload['currency'] ?? 'IDR')),
                'status' => (string) ($providerResponse['status'] ?? 'pending'),
                'payment_method' => $providerResponse['paymentMethod'] ?? ($payload['paymentMethod'] ?? null),
                'checkout_url' => $providerResponse['checkout_url'] ?? null,
                'qr_string' => $providerResponse['qr_string'] ?? null,
                'deeplink_url' => $providerResponse['deeplink_url'] ?? null,
                'va_number' => $providerResponse['va_number'] ?? null,
                'expiry_time' => $expiryTime,
                'payload_snapshot' => $providerResponse['raw'] ?? ($payload['payloadSnapshot'] ?? null),
                'provider_metadata_snapshot' => $providerResponse['provider_metadata'] ?? null,
                'expired_at' => $payload['expiredAt'] ?? null,
            ]);

            $this->recordEvent($transaction->id, 'status_changed', [
                'from' => null,
                'to' => 'pending',
                'source' => 'initiate',
            ]);
            $this->emitPaymentStatusChanged($transaction);

            return $this->transactionRepository->findById((int) $transaction->id) ?? $transaction;
        });
    }

    public function showTransaction(User $user, int $transactionId): PaymentTransaction
    {
        $transaction = $this->transactionRepository->findById($transactionId);
        if ($transaction === null) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class, [(string) $transactionId]);
        }

        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array((int) $transaction->outlet_id, $allowedOutletIds, true)) {
            throw (new ModelNotFoundException)->setModel(PaymentTransaction::class, [(string) $transactionId]);
        }

        return $transaction;
    }

    /** @param array<string,mixed> $payload */
    public function handleWebhook(string $provider, array $payload, array $headers = [], string $rawBody = ''): PaymentTransaction
    {
        $normalizedProvider = strtolower(trim($provider));
        $providerAdapter = $this->resolveProviderAdapter($normalizedProvider);
        $receipt = $this->persistWebhookReceipt($normalizedProvider, $payload, $headers);
        $transaction = $this->transactionRepository->findByProviderAndExternalReference($normalizedProvider, (string) $payload['externalReference']);
        if (! $providerAdapter->verifyWebhookSignature($payload, $headers, $rawBody)) {
            if ($transaction !== null) {
                $this->recordEvent((int) $transaction->id, 'signature_rejected', [
                    'provider' => $normalizedProvider,
                ]);
            }
            throw ValidationException::withMessages(['signature' => ['Invalid payment webhook signature.']]);
        }

        if ($receipt->processed_at !== null && $transaction !== null) {
            return $transaction->loadMissing('events');
        }

        try {
            return $this->processWebhookPayload($normalizedProvider, $payload, (int) $receipt->id);
        } catch (Throwable $throwable) {
            $nextDelaySeconds = $this->nextExponentialBackoffSeconds((int) $receipt->process_attempts);
            PaymentWebhookReceipt::query()->whereKey((int) $receipt->id)->update([
                'process_attempts' => DB::raw('process_attempts + 1'),
                'next_retry_at' => now()->addSeconds($nextDelaySeconds),
                'last_error' => mb_substr($throwable->getMessage(), 0, 1000),
            ]);
            ProcessPaymentWebhookReceiptJob::dispatch(
                (int) $receipt->id,
                AsyncOperationContext::capture([
                    'operation' => 'payments.process_webhook_receipt',
                    'webhook_receipt_id' => (int) $receipt->id,
                    'provider' => $normalizedProvider,
                    'external_reference' => (string) ($payload['externalReference'] ?? ''),
                ])
            )->delay(now()->addSeconds($nextDelaySeconds));
            throw $throwable;
        }
    }

    public function processWebhookReceipt(int $receiptId): ?PaymentTransaction
    {
        $receipt = PaymentWebhookReceipt::query()->find($receiptId);
        if ($receipt === null || $receipt->processed_at !== null) {
            return null;
        }

        $context = AsyncOperationContext::capture([
            'operation' => 'payments.process_webhook_receipt',
            'webhook_receipt_id' => $receiptId,
            'provider' => (string) $receipt->provider,
            'external_reference' => (string) $receipt->external_reference,
        ]);
        AsyncOperationContext::apply($context);

        /** @var array<string,mixed> $payload */
        $payload = is_array($receipt->payload) ? $receipt->payload : [];
        /** @var array<string,mixed> $headers */
        $headers = is_array($receipt->headers) ? $receipt->headers : [];

        try {
            Log::info('Processing persisted payment webhook receipt.', $context);
            $transaction = $this->handleWebhook((string) $receipt->provider, $payload, $headers, '');
            PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                'processed_at' => now(),
                'next_retry_at' => null,
                'last_error' => null,
            ]);

            return $transaction;
        } catch (Throwable $throwable) {
            $nextDelaySeconds = $this->nextExponentialBackoffSeconds((int) $receipt->process_attempts);
            PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                'process_attempts' => DB::raw('process_attempts + 1'),
                'next_retry_at' => now()->addSeconds($nextDelaySeconds),
                'last_error' => mb_substr($throwable->getMessage(), 0, 1000),
            ]);
            Log::warning('Payment webhook receipt processing deferred for retry.', AsyncOperationContext::capture([
                'webhook_receipt_id' => $receiptId,
                'error' => $throwable->getMessage(),
                'next_retry_seconds' => $nextDelaySeconds,
            ]));
            throw $throwable;
        }
    }

    public function markWebhookReceiptDeadLetter(int $receiptId, string $reason): void
    {
        PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
            'next_retry_at' => null,
            'last_error' => mb_substr('dead-letter prevented: '.$reason, 0, 1000),
        ]);
    }

    public function expireTransaction(int $transactionId): PaymentTransaction
    {
        return DB::transaction(function () use ($transactionId): PaymentTransaction {
            $transaction = $this->transactionRepository->findByIdForUpdate($transactionId);
            if ($transaction === null) {
                throw (new ModelNotFoundException)->setModel(PaymentTransaction::class, [(string) $transactionId]);
            }

            if (in_array((string) $transaction->status, ['paid', 'failed', 'cancelled', 'refunded'], true)) {
                return $transaction->loadMissing('events');
            }

            if ((string) $transaction->status !== 'expired') {
                $transaction = $this->transactionRepository->update($transaction, [
                    'status' => 'expired',
                    'expired_at' => now(),
                    'expiry_time' => now(),
                ]);
                $this->recordEvent((int) $transaction->id, 'status_changed', [
                    'from' => 'pending',
                    'to' => 'expired',
                    'source' => 'expire',
                ]);
                $this->recordEvent((int) $transaction->id, 'expired', [
                    'source' => 'expire',
                ]);
                $this->emitPaymentStatusChanged($transaction);
            }

            return $this->transactionRepository->findById((int) $transaction->id) ?? $transaction;
        });
    }

    public function reconcileTransaction(int $transactionId, string $status, array $payload = []): PaymentTransaction
    {
        return DB::transaction(function () use ($transactionId, $status, $payload): PaymentTransaction {
            $transaction = $this->transactionRepository->findByIdForUpdate($transactionId);
            if ($transaction === null) {
                throw (new ModelNotFoundException)->setModel(PaymentTransaction::class, [(string) $transactionId]);
            }

            if (! in_array($status, ['pending', 'authorized', 'paid', 'failed', 'expired', 'cancelled', 'refunded'], true)) {
                throw ValidationException::withMessages(['status' => ['Invalid reconciliation status.']]);
            }

            if (! $this->canTransition((string) $transaction->status, $status)) {
                $this->recordEvent((int) $transaction->id, 'reconciliation_result', [
                    'result' => 'ignored_stale',
                    'currentStatus' => (string) $transaction->status,
                    'incomingStatus' => $status,
                ]);

                return $transaction->loadMissing('events');
            }

            if ((string) $transaction->status !== $status) {
                $fromStatus = (string) $transaction->status;
                $transaction = $this->transactionRepository->update($transaction, [
                    'status' => $status,
                    'paid_at' => $status === 'paid' ? now() : $transaction->paid_at,
                    'expired_at' => $status === 'expired' ? now() : $transaction->expired_at,
                    'expiry_time' => $status === 'expired' ? now() : $transaction->expiry_time,
                ]);
                if ($status === 'paid') {
                    $this->postPaymentJournal($transaction);
                }
                if (in_array($status, ['paid', 'failed', 'expired'], true) && in_array($fromStatus, ['pending', 'authorized'], true)) {
                    $this->recordEvent((int) $transaction->id, 'stale_recovery', [
                        'from' => $fromStatus,
                        'to' => $status,
                    ]);
                }
                if ($status === 'expired') {
                    $this->recordEvent((int) $transaction->id, 'expired', [
                        'source' => 'reconcile',
                    ]);
                }
                if ($status === 'refunded') {
                    $this->recordEvent((int) $transaction->id, 'refunded', [
                        'source' => 'reconcile',
                    ]);
                }
                $this->emitPaymentStatusChanged($transaction);
            }

            $this->recordEvent((int) $transaction->id, 'reconciliation_result', [
                'result' => 'applied',
                'status' => $status,
                'payload' => $payload,
            ]);

            return $this->transactionRepository->findById((int) $transaction->id) ?? $transaction;
        });
    }

    /**
     * @param  array<int>  $transactionIds
     * @return Collection<int,PaymentTransaction>
     */
    public function reconcilePendingTransactions(array $transactionIds = [], int $limit = 50, array $context = []): Collection
    {
        AsyncOperationContext::apply(AsyncOperationContext::capture(array_merge($context, [
            'operation' => 'payments.reconcile_pending_batch',
            'limit' => $limit,
        ])));

        $candidates = count($transactionIds) > 0
            ? collect($transactionIds)->map(fn (int $id): ?PaymentTransaction => $this->transactionRepository->findById($id))->filter()
            : $this->transactionRepository->listPendingForReconciliation($limit);

        return $candidates->map(function (PaymentTransaction $transaction): PaymentTransaction {
            $transactionContext = AsyncOperationContext::capture([
                'transaction_id' => (int) $transaction->id,
                'outlet_id' => (int) $transaction->outlet_id,
                'provider' => (string) $transaction->provider,
                'external_reference' => (string) $transaction->external_reference,
            ]);
            AsyncOperationContext::apply($transactionContext);

            $this->recordEvent((int) $transaction->id, 'reconciliation_run', [
                'currentStatus' => (string) $transaction->status,
            ]);
            Log::info('Running payment reconciliation against provider.', $transactionContext);
            $providerAdapter = $this->resolveProviderAdapter((string) $transaction->provider);
            $providerResponse = $providerAdapter->reconcileTransaction((string) $transaction->external_reference, [
                'transactionId' => (int) $transaction->id,
                'currentStatus' => (string) $transaction->status,
            ]);
            $status = (string) ($providerResponse['status'] ?? $transaction->status);

            return $this->reconcileTransaction((int) $transaction->id, $status, $providerResponse);
        })->values();
    }

    public function reconcileTransactionById(int $transactionId): ?PaymentTransaction
    {
        $transaction = $this->transactionRepository->findById($transactionId);
        if ($transaction === null) {
            return null;
        }

        try {
            $reconciled = $this->reconcilePendingTransactions([$transactionId], 1)->first();
            PaymentTransaction::query()->whereKey($transactionId)->update([
                'reconciliation_attempts' => DB::raw('reconciliation_attempts + 1'),
                'last_reconciled_at' => now(),
                'async_retry_after' => null,
                'last_async_error' => null,
            ]);

            return $reconciled instanceof PaymentTransaction ? $reconciled : $transaction->fresh();
        } catch (Throwable $throwable) {
            $nextDelaySeconds = $this->nextExponentialBackoffSeconds((int) $transaction->reconciliation_attempts);
            PaymentTransaction::query()->whereKey($transactionId)->update([
                'reconciliation_attempts' => DB::raw('reconciliation_attempts + 1'),
                'last_reconciled_at' => now(),
                'async_retry_after' => now()->addSeconds($nextDelaySeconds),
                'last_async_error' => mb_substr($throwable->getMessage(), 0, 1000),
            ]);
            throw $throwable;
        }
    }

    public function dispatchPendingReconciliation(int $limit = 100, array $context = []): int
    {
        AsyncOperationContext::apply(AsyncOperationContext::capture(array_merge($context, [
            'operation' => 'payments.dispatch_reconciliation',
            'limit' => $limit,
        ])));

        return Cache::lock('payments:recover-stale-dispatch', 20)->block(3, function () use ($limit): int {
            $transactions = $this->transactionRepository->listPendingForReconciliation($limit);
            foreach ($transactions as $transaction) {
                ReconcilePaymentTransactionJob::dispatch(
                    (int) $transaction->id,
                    AsyncOperationContext::capture([
                        'operation' => 'payments.reconcile_transaction',
                        'transaction_id' => (int) $transaction->id,
                        'outlet_id' => (int) $transaction->outlet_id,
                        'provider' => (string) $transaction->provider,
                        'external_reference' => (string) $transaction->external_reference,
                    ])
                );
            }

            return $transactions->count();
        });
    }

    public function retryFailedAsyncPostings(int $limit = 50, array $context = []): int
    {
        AsyncOperationContext::apply(AsyncOperationContext::capture(array_merge($context, [
            'operation' => 'payments.retry_failed_async_postings',
            'limit' => $limit,
        ])));

        return Cache::lock('payments:retry-failed-async-postings', 20)->block(3, function () use ($limit): int {
            $candidates = PaymentTransaction::query()
                ->whereIn('status', ['pending', 'authorized'])
                ->whereNotNull('async_retry_after')
                ->where('async_retry_after', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($candidates as $candidate) {
                ReconcilePaymentTransactionJob::dispatch(
                    (int) $candidate->id,
                    AsyncOperationContext::capture([
                        'operation' => 'payments.reconcile_transaction',
                        'transaction_id' => (int) $candidate->id,
                        'outlet_id' => (int) $candidate->outlet_id,
                        'provider' => (string) $candidate->provider,
                        'external_reference' => (string) $candidate->external_reference,
                    ])
                );
            }

            return $candidates->count();
        });
    }

    public function markTransactionAsyncFailure(int $transactionId, string $message): void
    {
        $transaction = PaymentTransaction::query()->find($transactionId);
        if ($transaction === null) {
            return;
        }

        $nextDelaySeconds = $this->nextExponentialBackoffSeconds((int) $transaction->reconciliation_attempts);
        PaymentTransaction::query()->whereKey($transactionId)->update([
            'async_retry_after' => now()->addSeconds($nextDelaySeconds),
            'last_async_error' => mb_substr('queue-failed: '.$message, 0, 1000),
        ]);

        Log::error('Recorded async payment reconciliation failure.', AsyncOperationContext::capture([
            'operation' => 'payments.mark_transaction_async_failure',
            'transaction_id' => $transactionId,
            'outlet_id' => (int) $transaction->outlet_id,
            'provider' => (string) $transaction->provider,
            'external_reference' => (string) $transaction->external_reference,
            'error' => $message,
            'next_retry_seconds' => $nextDelaySeconds,
        ]));
    }

    /** @return Collection<int,PaymentTransaction> */
    public function expirePendingTransactions(int $limit = 100): Collection
    {
        $candidates = $this->transactionRepository->listPendingForExpiry($limit);

        return $candidates->map(function (PaymentTransaction $transaction): PaymentTransaction {
            $providerAdapter = $this->resolveProviderAdapter((string) $transaction->provider);
            $providerAdapter->expireOrCancelPayment((string) $transaction->external_reference);

            return $this->expireTransaction((int) $transaction->id);
        })->values();
    }

    /** @param array<string,mixed> $payload */
    private function processWebhookPayload(string $provider, array $payload, int $receiptId): PaymentTransaction
    {
        return DB::transaction(function () use ($provider, $payload, $receiptId): PaymentTransaction {
            $transaction = $this->transactionRepository->findByProviderAndExternalReference($provider, (string) $payload['externalReference']);
            if ($transaction === null) {
                throw (new ModelNotFoundException)->setModel(PaymentTransaction::class, [(string) $payload['externalReference']]);
            }
            $transaction = $this->transactionRepository->findByIdForUpdate((int) $transaction->id) ?? $transaction;

            $eventIdempotencyKey = $this->resolveWebhookEventKey($provider, $payload);
            if ($this->eventExists((int) $transaction->id, $eventIdempotencyKey)) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'duplicate_webhook_event',
                    'eventKey' => $eventIdempotencyKey,
                ]);
                PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                    'processed_at' => now(),
                    'next_retry_at' => null,
                    'last_error' => null,
                ]);

                return $transaction->refresh()->loadMissing('events');
            }

            $this->recordEvent((int) $transaction->id, 'webhook_received', [
                'provider' => $provider,
                'incomingStatus' => (string) $payload['status'],
                'occurredAt' => $payload['occurredAt'] ?? null,
                'raw' => $payload['payload'] ?? null,
            ], $eventIdempotencyKey);

            if ($this->isStaleEventTimestamp($payload['occurredAt'] ?? null)) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'stale_event_timestamp',
                    'occurredAt' => $payload['occurredAt'] ?? null,
                ]);
                PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                    'processed_at' => now(),
                    'next_retry_at' => null,
                    'last_error' => null,
                ]);

                return $transaction->refresh()->loadMissing('events');
            }

            $nextStatus = (string) $payload['status'];
            $currentStatus = (string) $transaction->status;
            if (! $this->canTransition($currentStatus, $nextStatus)) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'illegal_transition',
                    'currentStatus' => $currentStatus,
                    'incomingStatus' => $nextStatus,
                ]);
                PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                    'processed_at' => now(),
                    'next_retry_at' => null,
                    'last_error' => null,
                ]);

                return $transaction->refresh()->loadMissing('events');
            }

            if ($currentStatus === $nextStatus) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'same_status',
                    'status' => $nextStatus,
                ]);
                PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                    'processed_at' => now(),
                    'next_retry_at' => null,
                    'last_error' => null,
                ]);

                return $transaction->refresh()->loadMissing('events');
            }

            $updated = $this->transactionRepository->update($transaction, [
                'status' => $nextStatus,
                'payment_method' => $payload['paymentMethod'] ?? $transaction->payment_method,
                'paid_at' => $nextStatus === 'paid' ? now() : $transaction->paid_at,
                'expired_at' => $nextStatus === 'expired' ? now() : $transaction->expired_at,
                'expiry_time' => $nextStatus === 'expired' ? now() : $transaction->expiry_time,
                'payload_snapshot' => $payload['payload'] ?? $transaction->payload_snapshot,
            ]);

            $this->recordEvent((int) $updated->id, 'status_changed', [
                'from' => $currentStatus,
                'to' => $nextStatus,
                'source' => 'webhook',
            ]);
            if ($nextStatus === 'refunded') {
                $this->recordEvent((int) $updated->id, 'refunded', [
                    'source' => 'webhook',
                ]);
            }

            if ($currentStatus !== 'paid' && $nextStatus === 'paid') {
                $this->postPaymentJournal($updated);
            }
            $this->emitPaymentStatusChanged($updated);

            PaymentWebhookReceipt::query()->whereKey($receiptId)->update([
                'processed_at' => now(),
                'next_retry_at' => null,
                'last_error' => null,
            ]);

            return $this->transactionRepository->findById((int) $updated->id) ?? $updated;
        });
    }

    private function canTransition(string $current, string $incoming): bool
    {
        if ($current === $incoming) {
            return true;
        }

        $allowed = [
            'pending' => ['paid', 'failed', 'expired'],
            'authorized' => ['paid', 'failed', 'expired'],
            'paid' => ['refunded'],
        ];

        if (! array_key_exists($current, $allowed)) {
            return false;
        }

        return in_array($incoming, $allowed[$current], true);
    }

    private function isStaleEventTimestamp(mixed $occurredAt): bool
    {
        if (! is_string($occurredAt) || trim($occurredAt) === '') {
            return false;
        }

        try {
            $occurred = Carbon::parse($occurredAt);
        } catch (Throwable) {
            return false;
        }

        $maxAgeSeconds = (int) config('payments.webhook.max_event_age_seconds', 900);

        return $occurred->lt(now()->subSeconds($maxAgeSeconds));
    }

    private function eventExists(int $transactionId, string $eventIdempotencyKey): bool
    {
        return PaymentTransactionEvent::query()
            ->where('payment_transaction_id', $transactionId)
            ->where('event_idempotency_key', $eventIdempotencyKey)
            ->exists();
    }

    /** @param array<string,mixed> $payload */
    private function resolveWebhookEventKey(string $provider, array $payload): string
    {
        if (isset($payload['eventId']) && trim((string) $payload['eventId']) !== '') {
            return $provider.'#'.trim((string) $payload['eventId']);
        }

        return $provider.'#'.hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(int $transactionId, string $eventType, array $payload, ?string $eventIdempotencyKey = null): void
    {
        PaymentTransactionEvent::query()->create([
            'payment_transaction_id' => $transactionId,
            'event_type' => $eventType,
            'event_idempotency_key' => $eventIdempotencyKey,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function persistWebhookReceipt(string $provider, array $payload, array $headers = []): PaymentWebhookReceipt
    {
        $eventKey = $this->resolveWebhookEventKey($provider, $payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        /** @var PaymentWebhookReceipt $receipt */
        $receipt = PaymentWebhookReceipt::query()->firstOrCreate(
            [
                'provider' => $provider,
                'event_idempotency_key' => $eventKey,
            ],
            [
                'external_reference' => (string) ($payload['externalReference'] ?? ''),
                'incoming_status' => (string) ($payload['status'] ?? 'pending'),
                'payload_hash' => hash('sha256', $payloadJson),
                'payload' => $payload,
                'headers' => $headers,
                'process_attempts' => 0,
            ]
        );

        return $receipt;
    }

    private function nextExponentialBackoffSeconds(int $attempts): int
    {
        $base = max(1, (int) config('payments.recovery.backoff_base_seconds', 15));
        $max = max($base, (int) config('payments.recovery.backoff_max_seconds', 600));
        $value = (int) min($max, $base * (2 ** max(0, $attempts)));

        return $value;
    }

    private function postPaymentJournal(PaymentTransaction $transaction): void
    {
        $order = Order::query()->findOrFail((int) $transaction->order_id);
        $cash = $this->resolveAccount('cash_bank', ['1100'], ['asset'], (int) $transaction->outlet_id);
        $revenue = $this->resolveAccount('sales_revenue', ['4100'], ['revenue'], (int) $transaction->outlet_id);
        if ($cash === null || $revenue === null) {
            throw ValidationException::withMessages([
                'accounts' => ['Missing active cash or revenue account mapping for payment posting.'],
            ]);
        }

        $amount = (float) $transaction->amount;
        $this->journalPostingService->post([
            'tenant_id' => (int) ($order->tenant_id ?? 0),
            'outlet_id' => (int) $transaction->outlet_id,
            'source_type' => 'payment_transaction',
            'source_id' => (string) $transaction->id,
            'journal_date' => now()->toDateString(),
            'description' => 'Auto posting from payment transaction paid transition',
            'posting_key' => 'payment-transaction-'.$transaction->id,
            'scope' => 'payment_transaction.'.$transaction->id,
            'lines' => [
                [
                    'account_id' => (int) $cash->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Payment gateway settlement',
                ],
                [
                    'account_id' => (int) $revenue->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Revenue recognition',
                ],
            ],
        ]);

        $this->runGiftCardSettlementHook($transaction);
    }

    private function runGiftCardSettlementHook(PaymentTransaction $transaction): void
    {
        $snapshot = is_array($transaction->payload_snapshot) ? $transaction->payload_snapshot : [];
        $settlementIds = isset($snapshot['giftCardSettlementIds']) && is_array($snapshot['giftCardSettlementIds'])
            ? array_values(array_filter(array_map('intval', $snapshot['giftCardSettlementIds']), static fn (int $id): bool => $id > 0))
            : [];
        if ($settlementIds === []) {
            return;
        }

        try {
            $this->giftCardSettlementHookService->settle([
                'outletId' => (int) $transaction->outlet_id,
                'idempotencyKey' => 'payment-gift-card-settlement-'.$transaction->id,
                'settlementReference' => 'payment_transaction#'.$transaction->id,
                'paymentTransactionId' => (int) $transaction->id,
                'settlementStatus' => 'settled',
                'redeemSettlementIds' => $settlementIds,
                'meta' => ['trigger' => 'payment_paid_transition'],
            ]);
        } catch (Throwable $throwable) {
            Log::warning('Gift card settlement hook failed after payment posting.', [
                'transaction_id' => (int) $transaction->id,
                'outlet_id' => (int) $transaction->outlet_id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function resolveAccount(string $category, array $fallbackCodes, array $types, int $outletId): ?Account
    {
        $query = Account::query()->whereIn('type', $types)->where('is_active', true);
        $query->where(function ($q) use ($outletId): void {
            $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
        });

        $byCategory = (clone $query)->where('category', $category)->orderByRaw('outlet_id is null')->first();
        if ($byCategory !== null) {
            return $byCategory;
        }

        foreach ($fallbackCodes as $code) {
            $candidate = (clone $query)->where('code', $code)->orderByRaw('outlet_id is null')->first();
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return (clone $query)->orderBy('id')->first();
    }

    private function resolveProviderAdapter(string $provider): PaymentProviderInterface
    {
        $providerConfig = config('payments.providers.'.$provider);
        if (! is_array($providerConfig)) {
            throw ValidationException::withMessages(['provider' => ['Unsupported payment provider.']]);
        }

        $providerClass = $providerConfig['class'] ?? null;
        if (! is_string($providerClass) || ! class_exists($providerClass)) {
            throw ValidationException::withMessages(['provider' => ['Provider adapter is not configured.']]);
        }

        $adapter = app()->make($providerClass, ['config' => $providerConfig]);
        if (! $adapter instanceof PaymentProviderInterface) {
            throw ValidationException::withMessages(['provider' => ['Invalid provider adapter implementation.']]);
        }

        return $adapter;
    }

    private function emitPaymentStatusChanged(PaymentTransaction $transaction): void
    {
        event(new PaymentStatusChanged(
            outletId: (int) $transaction->outlet_id,
            transactionId: (int) $transaction->id,
            orderId: (int) $transaction->order_id,
            status: (string) $transaction->status,
            provider: (string) $transaction->provider,
            sequence: (int) $transaction->id,
            aggregateUpdatedAtIso: $transaction->updated_at?->toIso8601String()
        ));
    }
}
