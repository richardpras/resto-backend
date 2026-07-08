<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;

class InventoryConsumptionQueueService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    public function enqueueForPaidOrder(Order $order): InventoryConsumptionQueue
    {
        return DB::transaction(function () use ($order): InventoryConsumptionQueue {
            $order->loadMissing('items');
            $outletId = (int) ($order->outlet_id ?? 0);
            abort_if($outletId < 1, 422, 'Order outlet_id is required for inventory consumption queue.');

            $existing = InventoryConsumptionQueue::query()
                ->where('order_id', (int) $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $payload = [
                'orderCode' => (string) $order->code,
                'items' => $order->items->map(fn ($item): array => [
                    'id' => $item->item_id,
                    'name' => $item->name,
                    'qty' => (float) $item->qty,
                ])->values()->all(),
            ];

            $row = InventoryConsumptionQueue::query()->create([
                'outlet_id' => $outletId,
                'order_id' => (int) $order->id,
                'status' => InventoryConsumptionQueue::STATUS_PENDING,
                'payload' => $payload,
            ]);

            $this->auditLogService->log(
                'inventory.posting_created',
                'order',
                (int) $order->id,
                $outletId,
                null,
                ['queueId' => (int) $row->id, 'orderCode' => (string) $order->code],
            );

            return $row;
        });
    }

    /**
     * @return list<InventoryConsumptionQueue>
     */
    public function pendingForOutlet(int $outletId, ?int $limit = null): array
    {
        $query = InventoryConsumptionQueue::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', [
                InventoryConsumptionQueue::STATUS_PENDING,
                InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED,
            ])
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->all();
    }

    /** @return list<InventoryConsumptionQueue> */
    public function pendingForOutletOnBusinessDate(int $outletId, string $businessDate, ?int $limit = null): array
    {
        $query = InventoryConsumptionQueue::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', [
                InventoryConsumptionQueue::STATUS_PENDING,
                InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED,
            ])
            ->whereHas('order', function ($q) use ($businessDate): void {
                $q->where('payment_status', 'paid')
                    ->whereDate('updated_at', $businessDate);
            })
            ->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOutlet(int $outletId, ?string $status = null, int $limit = 50): array
    {
        $query = InventoryConsumptionQueue::query()
            ->where('outlet_id', $outletId)
            ->with(['order:id,code,total,payment_status'])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)));

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get()->map(fn (InventoryConsumptionQueue $row): array => [
            'id' => (int) $row->id,
            'outletId' => (int) $row->outlet_id,
            'orderId' => (int) $row->order_id,
            'orderCode' => (string) ($row->payload['orderCode'] ?? $row->order?->code ?? ''),
            'status' => (string) $row->status,
            'failureReason' => $row->failure_reason,
            'payload' => $row->payload,
            'createdAt' => $row->created_at?->toIso8601String(),
            'processedAt' => $row->processed_at?->toIso8601String(),
            'orderTotal' => $row->order !== null ? (float) $row->order->total : null,
        ])->all();
    }
}
