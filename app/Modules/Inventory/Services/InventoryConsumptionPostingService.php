<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Orders\Services\PosAuditLogService;
use Illuminate\Support\Facades\DB;

class InventoryConsumptionPostingService
{
    public function __construct(
        private readonly InventoryConsumptionQueueService $queueService,
        private readonly RecipeStockDeductionService $recipeStockDeductionService,
        private readonly OrderStockValidationService $orderStockValidationService,
        private readonly InventoryIncidentService $incidentService,
        private readonly JournalPostingService $journalPostingService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /**
     * @return array{processed: int, reviewRequired: int, failed: int, totalCogs: float}
     */
    public function processOutletForBusinessDate(int $outletId, string $businessDate, string $trigger = 'daily_stocktake'): array
    {
        if ($outletId < 1) {
            return ['processed' => 0, 'reviewRequired' => 0, 'failed' => 0, 'totalCogs' => 0.0];
        }

        $pending = $this->queueService->pendingForOutletOnBusinessDate($outletId, $businessDate);
        $processed = 0;
        $reviewRequired = 0;
        $failed = 0;
        $totalCogs = 0.0;

        foreach ($pending as $row) {
            $result = $this->processQueueRow($row, $trigger);
            $totalCogs += $result['cogs'];
            match ($result['status']) {
                InventoryConsumptionQueue::STATUS_PROCESSED => $processed++,
                InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED => $reviewRequired++,
                default => $failed++,
            };
        }

        if ($totalCogs > 0) {
            $this->postDeferredCogsJournal($outletId, $totalCogs, $trigger, $this->resolveTenantIdForOutlet($outletId));
        }

        return [
            'processed' => $processed,
            'reviewRequired' => $reviewRequired,
            'failed' => $failed,
            'totalCogs' => round($totalCogs, 2),
        ];
    }

    /**
     * @return array{processed: int, reviewRequired: int, failed: int, totalCogs: float}
     */
    public function processOutlet(?int $outletId, string $trigger = 'shift_close'): array
    {
        if ($outletId === null || $outletId < 1) {
            return ['processed' => 0, 'reviewRequired' => 0, 'failed' => 0, 'totalCogs' => 0.0];
        }

        $pending = $this->queueService->pendingForOutlet($outletId);
        $processed = 0;
        $reviewRequired = 0;
        $failed = 0;
        $totalCogs = 0.0;

        foreach ($pending as $row) {
            $result = $this->processQueueRow($row, $trigger);
            $totalCogs += $result['cogs'];
            match ($result['status']) {
                InventoryConsumptionQueue::STATUS_PROCESSED => $processed++,
                InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED => $reviewRequired++,
                default => $failed++,
            };
        }

        if ($totalCogs > 0) {
            $this->postDeferredCogsJournal($outletId, $totalCogs, $trigger, $this->resolveTenantIdForOutlet($outletId));
        }

        return [
            'processed' => $processed,
            'reviewRequired' => $reviewRequired,
            'failed' => $failed,
            'totalCogs' => round($totalCogs, 2),
        ];
    }

    /**
     * @return array{status: string, cogs: float}
     */
    private function processQueueRow(InventoryConsumptionQueue $row, string $trigger): array
    {
        return DB::transaction(function () use ($row, $trigger): array {
            /** @var InventoryConsumptionQueue|null $locked */
            $locked = InventoryConsumptionQueue::query()->whereKey($row->id)->lockForUpdate()->first();
            if ($locked === null) {
                return ['status' => InventoryConsumptionQueue::STATUS_FAILED, 'cogs' => 0.0];
            }

            if ($locked->status === InventoryConsumptionQueue::STATUS_PROCESSED) {
                return ['status' => InventoryConsumptionQueue::STATUS_PROCESSED, 'cogs' => 0.0];
            }

            /** @var Order|null $order */
            $order = Order::query()->whereKey($locked->order_id)->lockForUpdate()->first();
            if ($order === null || (string) $order->payment_status !== 'paid') {
                $locked->update([
                    'status' => InventoryConsumptionQueue::STATUS_FAILED,
                    'failure_reason' => 'Paid order not found for consumption posting.',
                ]);
                $this->incidentService->notifyPostingFailed(
                    (int) $locked->outlet_id,
                    (int) $locked->order_id,
                    (string) ($locked->payload['orderCode'] ?? $locked->order_id),
                    'Paid order not found for consumption posting.',
                );

                return ['status' => InventoryConsumptionQueue::STATUS_FAILED, 'cogs' => 0.0];
            }

            $order->loadMissing('items');
            $items = $order->items
                ->map(fn ($item): array => [
                    'id' => $item->item_id,
                    'name' => $item->name,
                    'qty' => (float) $item->qty,
                ])
                ->values()
                ->all();
            $shortagesBefore = $this->orderStockValidationService->collectShortages((int) $order->outlet_id, $items);
            $cogsBefore = $this->sumOrderMovementCost((string) $order->code);

            try {
                $this->recipeStockDeductionService->deductForPaidOrder($order->fresh(['items']), enforceNonNegative: false);
            } catch (\Throwable $e) {
                $locked->update([
                    'status' => InventoryConsumptionQueue::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                ]);
                $this->incidentService->notifyPostingFailed(
                    (int) $locked->outlet_id,
                    (int) $order->id,
                    (string) $order->code,
                    $e->getMessage(),
                );

                return ['status' => InventoryConsumptionQueue::STATUS_FAILED, 'cogs' => 0.0];
            }

            $cogsDelta = $this->sumOrderMovementCost((string) $order->code) - $cogsBefore;
            $shortages = $shortagesBefore;

            $status = InventoryConsumptionQueue::STATUS_PROCESSED;
            if ($shortages !== []) {
                $status = InventoryConsumptionQueue::STATUS_REVIEW_REQUIRED;
                $this->incidentService->recordPostingShortages($order, $shortages);
            }

            $locked->update([
                'status' => $status,
                'processed_at' => now(),
                'failure_reason' => $shortages !== []
                    ? 'Inventory variance detected during posting.'
                    : null,
            ]);

            $this->auditLogService->log(
                $status === InventoryConsumptionQueue::STATUS_PROCESSED
                    ? 'inventory.posting_processed'
                    : 'inventory.posting_failed',
                'order',
                (int) $order->id,
                (int) $order->outlet_id,
                null,
                [
                    'queueId' => (int) $locked->id,
                    'orderCode' => (string) $order->code,
                    'trigger' => $trigger,
                    'shortageCount' => count($shortages),
                ],
            );

            return ['status' => $status, 'cogs' => max(0.0, $cogsDelta)];
        });
    }

    private function sumOrderMovementCost(string $orderCode): float
    {
        return (float) DB::table('stock_movements')
            ->where('source_type', 'order_payment')
            ->where('source_id', $orderCode)
            ->sum('total_cost');
    }

    private function postDeferredCogsJournal(int $outletId, float $totalCogs, string $trigger, int $tenantId): void
    {
        if ($totalCogs <= 0) {
            return;
        }

        $batchKey = $trigger.'-'.$outletId.'-'.now()->format('YmdHis');

        $this->journalPostingService->postForDeferredInventoryConsumption(
            $tenantId,
            $outletId,
            round($totalCogs, 2),
            $batchKey,
        );
    }

    private function resolveTenantIdForOutlet(int $outletId): int
    {
        $fromOrder = (int) (DB::table('orders')
            ->where('outlet_id', $outletId)
            ->whereNotNull('tenant_id')
            ->orderByDesc('id')
            ->value('tenant_id') ?? 0);

        return $fromOrder > 0 ? $fromOrder : 1;
    }
}
