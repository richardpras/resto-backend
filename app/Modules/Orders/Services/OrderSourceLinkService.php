<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;

class OrderSourceLinkService
{
    /** @return array{type: string, label: string, code: string|null, id: int|null} */
    public function buildOrderSource(Order $order): array
    {
        $type = (string) ($order->source_type ?? '');
        if ($type === 'qr_order' && $order->source_id !== null) {
            return [
                'type' => 'qr_order',
                'label' => 'QR Order',
                'code' => $order->source_code !== null ? (string) $order->source_code : null,
                'id' => (int) $order->source_id,
            ];
        }

        if ($type === 'reservation' && $order->source_id !== null) {
            return [
                'type' => 'reservation',
                'label' => 'Reservation',
                'code' => $order->source_code !== null ? (string) $order->source_code : null,
                'id' => (int) $order->source_id,
            ];
        }

        if ($type === '' || $type === 'direct_pos') {
            $legacyQr = $this->resolveLegacyQrLink($order);
            if ($legacyQr !== null) {
                return $legacyQr;
            }

            return [
                'type' => 'direct_pos',
                'label' => 'Direct POS',
                'code' => null,
                'id' => null,
            ];
        }

        return [
            'type' => $type,
            'label' => ucfirst(str_replace('_', ' ', $type)),
            'code' => $order->source_code !== null ? (string) $order->source_code : null,
            'id' => $order->source_id !== null ? (int) $order->source_id : null,
        ];
    }

    /** @return array{id: int, orderNo: string, status: string, paymentStatus: string, total: float}|null */
    public function buildLinkedOrder(?QrOrderRequest $request): ?array
    {
        if ($request === null || $request->order_id === null) {
            return null;
        }

        $order = $request->relationLoaded('order')
            ? $request->order
            : Order::query()->find((int) $request->order_id);

        if ($order === null) {
            return null;
        }

        return [
            'id' => (int) $order->id,
            'orderNo' => (string) $order->code,
            'status' => (string) $order->status,
            'paymentStatus' => (string) $order->payment_status,
            'total' => (float) $order->total,
        ];
    }

    /** @return array{type: string, label: string, code: string|null, id: int|null}|null */
    private function resolveLegacyQrLink(Order $order): ?array
    {
        if ((string) $order->source !== 'qr' && (string) ($order->order_channel ?? '') !== 'qr') {
            return null;
        }

        $request = QrOrderRequest::query()
            ->where('order_id', (int) $order->id)
            ->first();

        if ($request === null) {
            return null;
        }

        return [
            'type' => 'qr_order',
            'label' => 'QR Order',
            'code' => (string) $request->request_code,
            'id' => (int) $request->id,
        ];
    }
}
