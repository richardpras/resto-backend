<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 store-credit issuance from recovery settlement (audit hook).
 */
final class OrderRecoveryStoreCreditService
{
    public function issueFromSettlement(User $manager, Order $order, float $amount, string $idempotencyKey): void
    {
        if ($amount <= 0) {
            return;
        }

        throw ValidationException::withMessages([
            'storeCreditAmount' => ['Automatic store credit issuance is not yet enabled. Record settlement audit and issue credit manually.'],
        ]);
    }
}
