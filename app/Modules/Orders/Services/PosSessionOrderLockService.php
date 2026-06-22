<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSessionOrderLockService
{
    public function isLockedByClosedSession(Order $order): bool
    {
        if (! in_array((string) $order->payment_status, ['paid'], true)) {
            return false;
        }

        $sessionId = $order->pos_session_id;
        if ($sessionId === null || (int) $sessionId < 1) {
            return false;
        }

        return PosSession::query()
            ->whereKey((int) $sessionId)
            ->where('status', 'closed')
            ->exists();
    }

    public function assertNotLockedByClosedSession(Order $order): void
    {
        if (! $this->isLockedByClosedSession($order)) {
            return;
        }

        throw ValidationException::withMessages([
            'posSessionId' => ['This order belongs to a closed cashier shift and cannot be modified.'],
        ]);
    }

    /** Manager refund execution bypasses shift lock; informational helper for callers. */
    public function allowsManagerRefundOnClosedSession(Order $order, User $user): bool
    {
        if (! $this->isLockedByClosedSession($order)) {
            return true;
        }

        return $user->hasPermission('orders.refund.execute');
    }

    public function releaseUnpaidOrdersFromSession(int $sessionId): int
    {
        return Order::query()
            ->where('pos_session_id', $sessionId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->update(['pos_session_id' => null]);
    }

    /** @return array{releasedUnpaidOrders: int} */
    public function onSessionClosed(int $sessionId): array
    {
        return [
            'releasedUnpaidOrders' => $this->releaseUnpaidOrdersFromSession($sessionId),
        ];
    }
}
