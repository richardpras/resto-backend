<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;

class QrOrderExpiryService
{
    public function __construct(
        private readonly QrOrderCustomerAuditService $customerAuditService,
    ) {}

    public function expirePendingRequests(): int
    {
        $expired = QrOrderRequest::query()
            ->whereIn('status', ['pending_cashier_confirmation', 'under_review'])
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $ids = $expired->pluck('id')->all();

        QrOrderRequest::query()
            ->whereIn('id', $ids)
            ->update(['status' => 'expired']);

        foreach ($expired as $request) {
            $request->status = 'expired';
            $this->customerAuditService->log('customer_order.expired', $request);
        }

        return count($ids);
    }

    public function markExpiredIfNeeded(QrOrderRequest $request): QrOrderRequest
    {
        if (
            in_array((string) $request->status, ['pending_cashier_confirmation', 'under_review'], true)
            && $request->expires_at !== null
            && $request->expires_at->isPast()
        ) {
            $request->status = 'expired';
            $request->save();
            $this->customerAuditService->log('customer_order.expired', $request);
        }

        return $request->fresh(['items', 'table', 'order']) ?? $request;
    }
}
