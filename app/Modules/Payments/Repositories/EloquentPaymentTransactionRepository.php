<?php

namespace App\Modules\Payments\Repositories;

use App\Models\Modules\Payments\Domain\PaymentTransaction;
use Illuminate\Support\Collection;

class EloquentPaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    public function create(array $attributes): PaymentTransaction
    {
        return PaymentTransaction::query()->create($attributes);
    }

    public function update(PaymentTransaction $transaction, array $attributes): PaymentTransaction
    {
        $transaction->fill($attributes)->save();

        return $transaction->refresh();
    }

    public function findById(int $id): ?PaymentTransaction
    {
        return PaymentTransaction::query()->with(['order', 'split', 'events'])->find($id);
    }

    public function findByProviderAndIdempotency(string $provider, string $idempotencyKey): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->with(['order', 'split', 'events'])
            ->where('provider', $provider)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByProviderAndExternalReference(string $provider, string $externalReference): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->with(['order', 'split', 'events'])
            ->where('provider', $provider)
            ->where('external_reference', $externalReference)
            ->first();
    }

    public function findByIdForUpdate(int $id): ?PaymentTransaction
    {
        return PaymentTransaction::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function listPendingForReconciliation(int $limit = 50): Collection
    {
        return PaymentTransaction::query()
            ->whereIn('status', ['pending', 'authorized'])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function listPendingForExpiry(int $limit = 50): Collection
    {
        return PaymentTransaction::query()
            ->whereIn('status', ['pending', 'authorized'])
            ->whereNotNull('expiry_time')
            ->where('expiry_time', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
