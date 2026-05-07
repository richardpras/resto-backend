<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;

class QrOrderExpiryService
{
    public function expirePendingRequests(): void
    {
        QrOrderRequest::query()
            ->where('status', 'pending_cashier_confirmation')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }

    public function markExpiredIfNeeded(QrOrderRequest $request): QrOrderRequest
    {
        if ((string) $request->status === 'pending_cashier_confirmation' && $request->expires_at !== null && $request->expires_at->isPast()) {
            $request->status = 'expired';
            $request->save();
        }

        return $request->fresh(['items', 'table', 'order']) ?? $request;
    }
}
