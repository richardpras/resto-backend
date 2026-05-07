<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Payments\Domain\PaymentTransactionEvent;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Payments\Repositories\PaymentTransactionRepositoryInterface;
use App\Modules\Payments\Services\Providers\PaymentProviderInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentGatewayService
{
    public function __construct(
        private readonly PaymentTransactionRepositoryInterface $transactionRepository,
        private readonly JournalPostingService $journalPostingService,
        private readonly OutletAccessResolver $outletAccessResolver,
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
                throw (new ModelNotFoundException())->setModel(Order::class, [(string) $payload['orderId']]);
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

            return $this->transactionRepository->findById((int) $transaction->id) ?? $transaction;
        });
    }

    public function showTransaction(User $user, int $transactionId): PaymentTransaction
    {
        $transaction = $this->transactionRepository->findById($transactionId);
        if ($transaction === null) {
            throw (new ModelNotFoundException())->setModel(PaymentTransaction::class, [(string) $transactionId]);
        }

        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array((int) $transaction->outlet_id, $allowedOutletIds, true)) {
            throw (new ModelNotFoundException())->setModel(PaymentTransaction::class, [(string) $transactionId]);
        }

        return $transaction;
    }

    /** @param array<string,mixed> $payload */
    public function handleWebhook(string $provider, array $payload, array $headers = [], string $rawBody = ''): PaymentTransaction
    {
        $normalizedProvider = strtolower(trim($provider));
        $providerAdapter = $this->resolveProviderAdapter($normalizedProvider);
        $transaction = $this->transactionRepository->findByProviderAndExternalReference($normalizedProvider, (string) $payload['externalReference']);
        if (! $providerAdapter->verifyWebhookSignature($payload, $headers, $rawBody)) {
            if ($transaction !== null) {
                $this->recordEvent((int) $transaction->id, 'signature_rejected', [
                    'provider' => $normalizedProvider,
                ]);
            }
            throw ValidationException::withMessages(['signature' => ['Invalid payment webhook signature.']]);
        }

        return DB::transaction(function () use ($normalizedProvider, $payload): PaymentTransaction {
            $transaction = $this->transactionRepository->findByProviderAndExternalReference($normalizedProvider, (string) $payload['externalReference']);
            if ($transaction === null) {
                throw (new ModelNotFoundException())->setModel(PaymentTransaction::class, [(string) $payload['externalReference']]);
            }
            $transaction = $this->transactionRepository->findByIdForUpdate((int) $transaction->id) ?? $transaction;

            $eventIdempotencyKey = $this->resolveWebhookEventKey($normalizedProvider, $payload);
            if ($this->eventExists((int) $transaction->id, $eventIdempotencyKey)) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'duplicate_webhook_event',
                    'eventKey' => $eventIdempotencyKey,
                ]);

                return $transaction->refresh()->loadMissing('events');
            }

            $this->recordEvent((int) $transaction->id, 'webhook_received', [
                'provider' => $normalizedProvider,
                'incomingStatus' => (string) $payload['status'],
                'occurredAt' => $payload['occurredAt'] ?? null,
                'raw' => $payload['payload'] ?? null,
            ], $eventIdempotencyKey);

            if ($this->isStaleEventTimestamp($payload['occurredAt'] ?? null)) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'stale_event_timestamp',
                    'occurredAt' => $payload['occurredAt'] ?? null,
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

                return $transaction->refresh()->loadMissing('events');
            }

            if ($currentStatus === $nextStatus) {
                $this->recordEvent((int) $transaction->id, 'duplicate_ignored', [
                    'reason' => 'same_status',
                    'status' => $nextStatus,
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

            return $this->transactionRepository->findById((int) $updated->id) ?? $updated;
        });
    }

    public function expireTransaction(int $transactionId): PaymentTransaction
    {
        return DB::transaction(function () use ($transactionId): PaymentTransaction {
            $transaction = $this->transactionRepository->findByIdForUpdate($transactionId);
            if ($transaction === null) {
                throw (new ModelNotFoundException())->setModel(PaymentTransaction::class, [(string) $transactionId]);
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
            }

            return $this->transactionRepository->findById((int) $transaction->id) ?? $transaction;
        });
    }

    public function reconcileTransaction(int $transactionId, string $status, array $payload = []): PaymentTransaction
    {
        return DB::transaction(function () use ($transactionId, $status, $payload): PaymentTransaction {
            $transaction = $this->transactionRepository->findByIdForUpdate($transactionId);
            if ($transaction === null) {
                throw (new ModelNotFoundException())->setModel(PaymentTransaction::class, [(string) $transactionId]);
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
     * @param array<int> $transactionIds
     * @return Collection<int,PaymentTransaction>
     */
    public function reconcilePendingTransactions(array $transactionIds = [], int $limit = 50): Collection
    {
        $candidates = count($transactionIds) > 0
            ? collect($transactionIds)->map(fn (int $id): ?PaymentTransaction => $this->transactionRepository->findById($id))->filter()
            : $this->transactionRepository->listPendingForReconciliation($limit);

        return $candidates->map(function (PaymentTransaction $transaction): PaymentTransaction {
            $this->recordEvent((int) $transaction->id, 'reconciliation_run', [
                'currentStatus' => (string) $transaction->status,
            ]);
            $providerAdapter = $this->resolveProviderAdapter((string) $transaction->provider);
            $providerResponse = $providerAdapter->reconcileTransaction((string) $transaction->external_reference, [
                'transactionId' => (int) $transaction->id,
                'currentStatus' => (string) $transaction->status,
            ]);
            $status = (string) ($providerResponse['status'] ?? $transaction->status);

            return $this->reconcileTransaction((int) $transaction->id, $status, $providerResponse);
        })->values();
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
            $occurred = \Illuminate\Support\Carbon::parse($occurredAt);
        } catch (\Throwable) {
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
}
