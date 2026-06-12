<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderLifecycleService
{
    public function __construct(
        private readonly QrOrderRequestRepositoryInterface $qrOrderRequestRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosAuditLogService $auditLogService,
        private readonly QrOrderCustomerAuditService $customerAuditService,
        private readonly QrOrderNotificationAdapter $notificationAdapter,
    ) {}

    public function markServed(User $user, int $requestId): QrOrderRequest
    {
        return DB::transaction(function () use ($user, $requestId): QrOrderRequest {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            $request = $this->qrOrderRequestRepository->findScoped($requestId, $allowed);
            if ($request === null) {
                throw ValidationException::withMessages([
                    'request' => ['QR order request not found.'],
                ]);
            }

            if (! in_array((string) $request->status, ['confirmed', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'request' => ['Only confirmed QR orders can be marked as served.'],
                ]);
            }

            $servedAt = now();
            $this->qrOrderRequestRepository->update($request, [
                'customer_served_at' => $servedAt,
            ]);

            if ($request->order_id !== null) {
                Order::query()
                    ->whereKey((int) $request->order_id)
                    ->where('status', '!=', 'cancelled')
                    ->update(['kitchen_status' => 'served']);
            }

            $this->customerAuditService->log('customer_order.served', $request, $user);
            $this->notificationAdapter->qrOrderReadyOrServed($request, 'served');

            return $request->fresh(['items.menuItem', 'table', 'order.items']);
        });
    }

    public function approveAdjustments(string $orderCode): QrOrderRequest
    {
        $normalized = strtoupper(trim($orderCode));
        $request = QrOrderRequest::query()
            ->where('request_code', $normalized)
            ->with(['items.menuItem', 'table', 'order.items'])
            ->first();

        if ($request === null) {
            throw ValidationException::withMessages([
                'orderCode' => ['QR order not found.'],
            ]);
        }

        if ((string) ($request->customer_approval_status ?? '') !== 'pending_approval') {
            throw ValidationException::withMessages([
                'orderCode' => ['No customer approval is required for this order.'],
            ]);
        }

        $this->qrOrderRequestRepository->update($request, [
            'customer_approval_status' => 'approved',
        ]);

        return $request->fresh(['items.menuItem', 'table', 'order.items']);
    }
}
