<?php

namespace App\Modules\Payments\Repositories;

use App\Models\Modules\Payments\Domain\PaymentTransaction;

interface PaymentTransactionRepositoryInterface
{
    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): PaymentTransaction;

    /** @param array<string,mixed> $attributes */
    public function update(PaymentTransaction $transaction, array $attributes): PaymentTransaction;

    public function findById(int $id): ?PaymentTransaction;

    public function findByProviderAndIdempotency(string $provider, string $idempotencyKey): ?PaymentTransaction;

    public function findByProviderAndExternalReference(string $provider, string $externalReference): ?PaymentTransaction;

    public function findByIdForUpdate(int $id): ?PaymentTransaction;

    /** @return \Illuminate\Support\Collection<int,PaymentTransaction> */
    public function listPendingForReconciliation(int $limit = 50): \Illuminate\Support\Collection;

    /** @return \Illuminate\Support\Collection<int,PaymentTransaction> */
    public function listPendingForExpiry(int $limit = 50): \Illuminate\Support\Collection;
}
