<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;

class QrOrderCustomerStatusService
{
    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    public function resolve(QrOrderRequest $request): array
    {
        $requestStatus = (string) $request->status;

        if ($requestStatus === 'rejected' || $requestStatus === 'expired') {
            return $this->pack('cancelled', 'cancelled', 'Dibatalkan', null, true);
        }

        if ($this->hasPendingAdjustments($request)) {
            if ((string) ($request->customer_approval_status ?? '') === 'pending_approval') {
                return $this->pack('adjusted', 'adjusted', 'Menunggu persetujuan Anda', null, false);
            }

            return $this->pack('adjusted', 'adjusted', 'Diubah kasir', null, false);
        }

        if ($requestStatus === 'pending_cashier_confirmation') {
            return $this->pack('pending_review', 'pending_review', 'Menunggu review kasir', 0, false);
        }

        if ($requestStatus === 'under_review') {
            return $this->pack('under_review', 'under_review', 'Sedang direview kasir', 0, false);
        }

        if ($requestStatus === 'confirmed' || $requestStatus === 'paid') {
            $order = $request->relationLoaded('order') ? $request->order : $request->order()->first();
            if ($order === null) {
                return $this->pack('confirmed', 'confirmed', 'Dikonfirmasi', 1, false);
            }

            if ((string) $order->status === 'cancelled') {
                return $this->pack('cancelled', 'cancelled', 'Dibatalkan', null, true);
            }

            if ($request->customer_served_at !== null) {
                return $this->pack('served', 'served', 'Pesanan sedang diantar', 4, false);
            }

            if ($this->isAdjusted($request, $order)) {
                return $this->pack('adjusted', 'adjusted', 'Diubah kasir', null, false);
            }

            return $this->resolveFromKitchenStatus((string) ($order->kitchen_status ?? 'queued'), (string) $order->payment_status);
        }

        return $this->pack($requestStatus, $requestStatus, $requestStatus, null, false);
    }

    /** @return list<array{key: string, label: string}> */
    public function timelineSteps(): array
    {
        return [
            ['key' => 'pending_review', 'label' => 'Pesanan dikirim'],
            ['key' => 'confirmed', 'label' => 'Dikonfirmasi'],
            ['key' => 'cooking', 'label' => 'Sedang dimasak'],
            ['key' => 'ready', 'label' => 'Siap diantar'],
            ['key' => 'served', 'label' => 'Sudah diantar'],
            ['key' => 'completed', 'label' => 'Selesai'],
        ];
    }

    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    private function resolveFromKitchenStatus(string $kitchenStatus, string $paymentStatus): array
    {
        if ($kitchenStatus === 'completed' || ($kitchenStatus === 'served' && $paymentStatus === 'paid')) {
            return $this->pack('completed', 'completed', 'Selesai', 5, true);
        }

        return match ($kitchenStatus) {
            'queued' => $this->pack('confirmed', 'confirmed', 'Dikonfirmasi', 1, false),
            'preparing', 'in_kitchen', 'cooking' => $this->pack('cooking', 'cooking', 'Sedang dimasak', 2, false),
            'ready' => $this->pack('ready', 'ready', 'Siap diantar', 3, false),
            'served' => $this->pack('served', 'served', 'Sudah diantar', 4, false),
            default => $this->pack('confirmed', 'confirmed', 'Dikonfirmasi', 1, false),
        };
    }

    /** @return array{status: string, customerStatus: string, customerStatusLabel: string, timelineStep: int|null, isTerminal: bool} */
    private function pack(
        string $status,
        string $customerStatus,
        string $customerStatusLabel,
        ?int $timelineStep,
        bool $isTerminal,
    ): array {
        return [
            'status' => $status,
            'customerStatus' => $customerStatus,
            'customerStatusLabel' => $customerStatusLabel,
            'timelineStep' => $timelineStep,
            'isTerminal' => $isTerminal,
        ];
    }

    private function hasPendingAdjustments(QrOrderRequest $request): bool
    {
        if ($this->hasReviewAdjustments($request)) {
            return true;
        }

        $log = $request->adjustment_log;
        if (! is_array($log) || $log === []) {
            return false;
        }

        return collect($log)->contains(function (array $entry): bool {
            $summary = $entry['summary'] ?? [];

            return is_array($summary) && $summary !== [];
        });
    }

    private function isAdjusted(QrOrderRequest $request, Order $order): bool
    {
        if (is_array($request->original_items_snapshot) && $request->original_items_snapshot !== []) {
            return app(QrOrderPosIntegrationService::class)->buildPosAdjustments($request, $order) !== [];
        }

        $requestItems = $request->relationLoaded('items') ? $request->items : $request->items()->with('menuItem')->get();
        $orderItems = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        if ($requestItems->count() !== $orderItems->count()) {
            return true;
        }

        $requestLines = $requestItems
            ->map(function ($item): string {
                $menuItemId = (int) $item->menu_item_id;
                $qty = number_format((float) $item->qty, 3, '.', '');
                $notes = trim((string) ($item->notes ?? ''));

                return "{$menuItemId}|{$qty}|{$notes}";
            })
            ->sort()
            ->values()
            ->all();

        $orderLines = $orderItems
            ->map(function ($item): string {
                $menuItemId = (int) ($item->item_id ?? 0);
                $qty = number_format((float) $item->qty, 3, '.', '');
                $notes = trim((string) ($item->notes ?? ''));

                return "{$menuItemId}|{$qty}|{$notes}";
            })
            ->sort()
            ->values()
            ->all();

        return $requestLines !== $orderLines;
    }

    private function hasReviewAdjustments(QrOrderRequest $request): bool
    {
        $draft = is_array($request->review_draft) ? $request->review_draft : null;
        if ($draft === null) {
            return false;
        }

        return ($draft['adjustments'] ?? []) !== [];
    }
}
