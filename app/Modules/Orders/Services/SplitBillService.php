<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SplitBillService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly OptimisticConcurrencyService $optimisticConcurrencyService,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function createSplit(User $user, int $orderId, array $payload, ?string $idempotencyKey = null, ?string $expectedUpdatedAt = null): OrderSplit
    {
        return DB::transaction(function () use ($user, $orderId, $payload, $idempotencyKey, $expectedUpdatedAt): OrderSplit {
            return $this->idempotencyService->run(
                'orders.splits.create.'.$orderId,
                $idempotencyKey,
                ['payload' => $payload, 'expectedUpdatedAt' => $expectedUpdatedAt],
                function () use ($user, $orderId, $payload, $expectedUpdatedAt): OrderSplit {
                    $order = $this->findScopedOrderForUpdate($user, $orderId);
                    $this->optimisticConcurrencyService->assertNotStale($order, $expectedUpdatedAt);
                    $this->assertOrderEditable($order);

                    $split = OrderSplit::query()->create([
                        'order_id' => $order->id,
                        'split_type' => (string) ($payload['splitType'] ?? 'mixed'),
                        'label' => (string) ($payload['label'] ?? ('Split #'.now()->timestamp)),
                        'status' => (string) ($payload['status'] ?? 'open'),
                    ]);

                    $items = collect($payload['items'] ?? [])->map(fn (array $item): array => [
                        'orderItemId' => (int) ($item['orderItemId'] ?? 0),
                        'qty' => (float) ($item['qty'] ?? 0),
                        'amount' => (float) ($item['amount'] ?? 0),
                    ])->values()->all();
                    $this->validateSplitItems($order, $items, null);
                    $this->replaceSplitItems($split, $items);
                    $this->auditLogService->log('split.created', 'order_split', (int) $split->id, (int) $order->outlet_id, $user, ['orderId' => (int) $order->id]);

                    return $split->fresh('items') ?? $split->load('items');
                }
            );
        });
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateSplit(User $user, int $orderId, int $splitId, array $payload, ?string $idempotencyKey = null, ?string $expectedUpdatedAt = null): OrderSplit
    {
        return DB::transaction(function () use ($user, $orderId, $splitId, $payload, $idempotencyKey, $expectedUpdatedAt): OrderSplit {
            return $this->idempotencyService->run(
                'orders.splits.update.'.$splitId,
                $idempotencyKey,
                ['payload' => $payload, 'expectedUpdatedAt' => $expectedUpdatedAt],
                function () use ($user, $orderId, $splitId, $payload, $expectedUpdatedAt): OrderSplit {
                    $order = $this->findScopedOrderForUpdate($user, $orderId);
                    $this->optimisticConcurrencyService->assertNotStale($order, $expectedUpdatedAt);
                    $this->assertOrderEditable($order);

                    $split = OrderSplit::query()
                        ->where('order_id', $order->id)
                        ->whereKey($splitId)
                        ->first();
                    if ($split === null) {
                        throw (new ModelNotFoundException)->setModel(OrderSplit::class, [(string) $splitId]);
                    }

                    $updates = [];
                    if (array_key_exists('splitType', $payload)) {
                        $updates['split_type'] = (string) $payload['splitType'];
                    }
                    if (array_key_exists('label', $payload)) {
                        $updates['label'] = (string) $payload['label'];
                    }
                    if (array_key_exists('status', $payload)) {
                        $updates['status'] = (string) $payload['status'];
                    }
                    if ($updates !== []) {
                        $split->fill($updates)->save();
                    }

                    if (array_key_exists('items', $payload) && is_array($payload['items'])) {
                        $items = collect($payload['items'])->map(fn (array $item): array => [
                            'orderItemId' => (int) ($item['orderItemId'] ?? 0),
                            'qty' => (float) ($item['qty'] ?? 0),
                            'amount' => (float) ($item['amount'] ?? 0),
                        ])->values()->all();
                        $this->validateSplitItems($order, $items, $split->id);
                        $this->replaceSplitItems($split, $items);
                    }
                    $this->auditLogService->log('split.updated', 'order_split', (int) $split->id, (int) $order->outlet_id, $user, ['orderId' => (int) $order->id]);

                    return $split->fresh('items') ?? $split->load('items');
                }
            );
        });
    }

    private function findScopedOrderForUpdate(User $user, int $orderId): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = Order::query()
            ->whereIn('outlet_id', $allowed)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $order;
    }

    private function assertOrderEditable(Order $order): void
    {
        if (! in_array((string) $order->payment_status, ['unpaid', 'partial'], true)) {
            throw ValidationException::withMessages([
                'paymentStatus' => ['Only unpaid/partial orders can be split or updated.'],
            ]);
        }
    }

    /**
     * @param array<int,array{orderItemId:int,qty:float,amount:float}> $items
     */
    private function validateSplitItems(Order $order, array $items, ?int $excludeSplitId): void
    {
        $orderItems = OrderItem::query()
            ->where('order_id', $order->id)
            ->get(['id', 'qty', 'line_total'])
            ->keyBy('id');

        $ids = collect($items)->pluck('orderItemId');
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                'items' => ['Duplicate item allocation inside split payload is not allowed.'],
            ]);
        }

        $existingQty = OrderSplitItem::query()
            ->selectRaw('order_item_id, SUM(qty) as qty_sum')
            ->whereHas('split', function ($query) use ($order, $excludeSplitId): void {
                $query->where('order_id', $order->id);
                if ($excludeSplitId !== null) {
                    $query->where('id', '!=', $excludeSplitId);
                }
            })
            ->groupBy('order_item_id')
            ->pluck('qty_sum', 'order_item_id')
            ->map(fn ($qty): float => (float) $qty);

        $existingAmount = OrderSplitItem::query()
            ->selectRaw('order_item_id, SUM(amount) as amount_sum')
            ->whereHas('split', function ($query) use ($order, $excludeSplitId): void {
                $query->where('order_id', $order->id);
                if ($excludeSplitId !== null) {
                    $query->where('id', '!=', $excludeSplitId);
                }
            })
            ->groupBy('order_item_id')
            ->pluck('amount_sum', 'order_item_id')
            ->map(fn ($amount): float => (float) $amount);

        foreach ($items as $item) {
            $orderItemId = $item['orderItemId'];
            $row = $orderItems->get($orderItemId);
            if ($row === null) {
                throw ValidationException::withMessages([
                    'items' => ['Split item does not belong to this order.'],
                ]);
            }

            if ($item['qty'] <= 0 || $item['amount'] <= 0) {
                throw ValidationException::withMessages([
                    'items' => ['Split qty and amount must be greater than zero.'],
                ]);
            }

            if (((float) $existingQty->get($orderItemId, 0) + $item['qty']) > ((float) $row->qty + 0.00001)) {
                throw ValidationException::withMessages([
                    'items' => ['Split allocation qty exceeds order item qty.'],
                ]);
            }

            if (((float) $existingAmount->get($orderItemId, 0) + $item['amount']) > ((float) $row->line_total + 0.00001)) {
                throw ValidationException::withMessages([
                    'items' => ['Split allocation amount exceeds order item line total.'],
                ]);
            }
        }
    }

    /**
     * @param array<int,array{orderItemId:int,qty:float,amount:float}> $items
     */
    private function replaceSplitItems(OrderSplit $split, array $items): void
    {
        OrderSplitItem::query()->where('order_split_id', $split->id)->delete();
        foreach ($items as $item) {
            OrderSplitItem::query()->create([
                'order_split_id' => $split->id,
                'order_item_id' => $item['orderItemId'],
                'qty' => $item['qty'],
                'amount' => $item['amount'],
            ]);
        }
    }
}
