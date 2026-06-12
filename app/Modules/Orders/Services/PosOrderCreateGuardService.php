<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\User;
use App\Modules\Orders\DTOs\CreateOrderData;
class PosOrderCreateGuardService
{
    public function __construct(
        private readonly PosCheckoutIntegrityService $integrityService,
    ) {}

    /**
     * @return array{order: Order, reason: string}|null
     */
    public function resolveResumableOrder(CreateOrderData $data, ?User $user = null): ?array
    {
        if ($data->qrOrderRequestId !== null) {
            $qrResume = $this->resolveQrLinkedOpenOrder((int) $data->qrOrderRequestId);
            if ($qrResume !== null) {
                $this->integrityService->recordResumeExistingOrder($qrResume, 'qr_order_linked', $user, [
                    'qrOrderRequestId' => (int) $data->qrOrderRequestId,
                ]);

                return ['order' => $qrResume, 'reason' => 'qr_order_linked'];
            }
        }

        $fingerprintResume = $this->resolveOpenBillByCartFingerprint($data);
        if ($fingerprintResume !== null) {
            $this->integrityService->recordResumeExistingOrder($fingerprintResume, 'cart_fingerprint', $user, [
                'fingerprint' => $this->cartFingerprint($data->items),
            ]);

            return ['order' => $fingerprintResume, 'reason' => 'cart_fingerprint'];
        }

        return null;
    }

    public function resolveQrLinkedOpenOrder(int $qrOrderRequestId): ?Order
    {
        $request = QrOrderRequest::query()
            ->whereKey($qrOrderRequestId)
            ->first(['id', 'order_id']);

        if ($request === null || $request->order_id === null || (int) $request->order_id < 1) {
            return null;
        }

        /** @var Order|null $order */
        $order = Order::query()
            ->whereKey((int) $request->order_id)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->first();

        return $order?->loadMissing(['items', 'payments']);
    }

    public function resolveOpenBillByCartFingerprint(CreateOrderData $data): ?Order
    {
        if ($data->outletId === null || (int) $data->outletId < 1 || $data->items === []) {
            return null;
        }

        $fingerprint = $this->cartFingerprint($data->items);
        if ($fingerprint === '') {
            return null;
        }

        $candidates = Order::query()
            ->where('outlet_id', (int) $data->outletId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->when($data->tableId !== null, fn ($q) => $q->where('table_id', (int) $data->tableId))
            ->orderByDesc('id')
            ->limit(20)
            ->with('items')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->cartFingerprintFromOrder($candidate) === $fingerprint) {
                return $candidate->loadMissing(['items', 'payments']);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function cartFingerprint(array $items): string
    {
        $rows = collect($items)
            ->map(fn (array $item): array => [
                'id' => (int) ($item['id'] ?? $item['item_id'] ?? 0),
                'qty' => (float) ($item['qty'] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['id'] > 0 && $row['qty'] > 0)
            ->sortBy('id')
            ->values()
            ->all();

        if ($rows === []) {
            return '';
        }

        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function cartFingerprintFromOrder(Order $order): string
    {
        $items = $order->items
            ->map(fn ($item): array => [
                'id' => (int) ($item->item_id ?? 0),
                'qty' => (float) $item->qty,
            ])
            ->filter(fn (array $row): bool => $row['id'] > 0 && $row['qty'] > 0)
            ->sortBy('id')
            ->values()
            ->all();

        if ($items === []) {
            return '';
        }

        return hash('sha256', json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
