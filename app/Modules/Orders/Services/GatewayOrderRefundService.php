<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 gateway refund orchestration placeholder.
 * QR / e-wallet refunds require provider integration and webhook confirmation.
 */
final class GatewayOrderRefundService
{
    /**
     * @return array{status: string, providerReference: string|null, message: string}
     */
    public function initiateRefund(Order $order, float $amount, User $actor): array
    {
        if ((string) $order->source !== 'qr') {
            throw ValidationException::withMessages([
                'source' => ['Gateway refund is only applicable to QR orders.'],
            ]);
        }

        throw ValidationException::withMessages([
            'gateway' => ['Gateway refund integration is not yet enabled for this outlet. Use manager cash settlement workflow or contact support.'],
        ]);
    }
}
