<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class QrTableActiveOrderService
{
    public function __construct(
        private readonly QrOrderCustomerStatusService $customerStatusService,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
    ) {}

    /** @return array<string, mixed> */
    public function resolveForTable(RestaurantTable $table): array
    {
        $activeQr = $this->findActiveQrRequest((int) $table->outlet_id, (int) $table->id);
        $openBill = $this->findActiveOpenBill((int) $table->outlet_id, (int) $table->id);

        $payload = [
            'hasActiveSession' => $activeQr !== null || $openBill !== null,
            'activeQrOrder' => null,
            'activePosOrder' => null,
            'activeOpenBill' => null,
        ];

        if ($activeQr !== null) {
            $activeQr = $this->qrOrderExpiryService->markExpiredIfNeeded($activeQr);
            if (! in_array((string) $activeQr->status, ['rejected', 'expired'], true)) {
                $status = $this->customerStatusService->resolve($activeQr);
                $payload['activeQrOrder'] = [
                    'id' => (int) $activeQr->id,
                    'requestCode' => (string) $activeQr->request_code,
                    'status' => (string) $activeQr->status,
                    'customerStatus' => $status['customerStatus'],
                    'customerStatusLabel' => $status['customerStatusLabel'],
                    'detailUrl' => '/qr/order/'.urlencode((string) $activeQr->request_code),
                ];
            }
        }

        if ($openBill !== null) {
            $payload['activeOpenBill'] = [
                'orderId' => (int) $openBill->id,
                'orderCode' => (string) $openBill->code,
                'paymentStatus' => (string) $openBill->payment_status,
                'total' => (float) $openBill->total,
                'kitchenStatus' => (string) ($openBill->kitchen_status ?? 'queued'),
            ];
            $payload['activePosOrder'] = $payload['activeOpenBill'];
        }

        if ($activeQr !== null && $activeQr->order !== null && $payload['activePosOrder'] === null) {
            $order = $activeQr->order;
            $payload['activePosOrder'] = [
                'orderId' => (int) $order->id,
                'orderCode' => (string) $order->code,
                'paymentStatus' => (string) $order->payment_status,
                'total' => (float) $order->total,
                'kitchenStatus' => (string) ($order->kitchen_status ?? 'queued'),
            ];
        }

        $payload['hasActiveSession'] = $payload['activeQrOrder'] !== null
            || $payload['activePosOrder'] !== null
            || $payload['activeOpenBill'] !== null;

        return $payload;
    }

    public function resolveByPublicId(string $qrPublicId): array
    {
        $table = RestaurantTable::query()
            ->where('qr_public_id', trim($qrPublicId))
            ->where('status', 'active')
            ->first();

        if ($table === null) {
            throw (new ModelNotFoundException())->setModel(RestaurantTable::class, [$qrPublicId]);
        }

        return $this->resolveForTable($table);
    }

    public function findActiveQrRequest(int $outletId, int $tableId): ?QrOrderRequest
    {
        return QrOrderRequest::query()
            ->where('outlet_id', $outletId)
            ->where('table_id', $tableId)
            ->whereNotIn('status', ['rejected', 'expired'])
            ->where(function ($query): void {
                $query
                    ->whereIn('status', ['pending_cashier_confirmation', 'under_review'])
                    ->orWhere(function ($inner): void {
                        $inner
                            ->whereIn('status', ['confirmed', 'paid'])
                            ->whereHas('order', function ($orderQuery): void {
                                $orderQuery
                                    ->where('status', '!=', 'cancelled')
                                    ->where(function ($paymentQuery): void {
                                        $paymentQuery
                                            ->whereIn('payment_status', ['unpaid', 'partial'])
                                            ->orWhereNotIn('kitchen_status', ['served', 'completed']);
                                    });
                            });
                    });
            })
            ->with(['items.menuItem', 'table', 'order'])
            ->orderByDesc('id')
            ->first();
    }

    private function findActiveOpenBill(int $outletId, int $tableId): ?\App\Models\Modules\Orders\Domain\Order
    {
        return \App\Models\Modules\Orders\Domain\Order::query()
            ->where('outlet_id', $outletId)
            ->where('table_id', $tableId)
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->orderByDesc('id')
            ->first();
    }
}
