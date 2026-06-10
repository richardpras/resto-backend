<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\User;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseOrderLifecycleService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ApprovalNotificationService $approvalNotificationService,
    ) {}

    public function submit(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertOutlet($actor, $purchaseOrder);
        abort_if($purchaseOrder->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft purchase orders can be submitted.');
        $purchaseOrder->loadCount('items');
        abort_if($purchaseOrder->items_count < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot submit a purchase order without items.');

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            $fresh = $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
            $this->purchaseAuditService->logPurchaseOrderLifecycle('submitted', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);
            $this->approvalNotificationService->purchaseOrderSubmitted($fresh, $actor);

            return $fresh;
        });
    }

    public function approve(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertOutlet($actor, $purchaseOrder);
        abort_if($purchaseOrder->status !== 'submitted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only submitted purchase orders can be approved.');

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $fresh = $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
            $this->purchaseAuditService->logPurchaseOrderLifecycle('approved', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
                'approvedBy' => $actor->id,
            ]);
            $this->approvalNotificationService->purchaseOrderApproved($fresh, $actor);

            return $fresh;
        });
    }

    public function reject(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertOutlet($actor, $purchaseOrder);
        abort_if($purchaseOrder->status !== 'submitted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only submitted purchase orders can be rejected.');

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $fresh = $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
            $this->purchaseAuditService->logPurchaseOrderLifecycle('rejected', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);
            $this->approvalNotificationService->purchaseOrderRejected($fresh, $actor);

            return $fresh;
        });
    }

    public function cancel(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertOutlet($actor, $purchaseOrder);

        if ($purchaseOrder->status === 'approved') {
            abort_if(
                $purchaseOrder->goodsReceivingNotes()->exists(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Approved purchase orders with goods receipts cannot be cancelled.'
            );
        } else {
            abort_if(
                ! in_array($purchaseOrder->status, ['draft', 'submitted'], true),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Only draft or submitted purchase orders can be cancelled.'
            );
        }

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $fresh = $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
            $this->purchaseAuditService->logPurchaseOrderLifecycle('cancelled', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);

            return $fresh;
        });
    }

    public function close(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $this->assertOutlet($actor, $purchaseOrder);
        abort_if($purchaseOrder->status !== 'received', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only fully received purchase orders can be closed.');

        return DB::transaction(function () use ($purchaseOrder, $actor): PurchaseOrder {
            $purchaseOrder->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            $fresh = $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
            $this->purchaseAuditService->logPurchaseOrderLifecycle('closed', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);

            return $fresh;
        });
    }

    public function recalculateReceivingProgress(PurchaseOrder $purchaseOrder, ?User $actor = null): PurchaseOrder
    {
        $purchaseOrder->load('items');
        $previousStatus = $purchaseOrder->status;

        if (! in_array($previousStatus, ['approved', 'partially_received', 'received'], true)) {
            return $purchaseOrder;
        }

        $progress = $this->calculateProgress($purchaseOrder);
        $totalReceived = $progress['totalReceivedQty'];
        $allReceived = $purchaseOrder->items->every(
            static fn ($item): bool => (float) $item->received_qty >= (float) $item->ordered_qty
        );

        $newStatus = match (true) {
            $allReceived => 'received',
            $totalReceived > 0 => 'partially_received',
            default => 'approved',
        };

        if ($newStatus !== $previousStatus) {
            $purchaseOrder->update(['status' => $newStatus]);

            if ($actor !== null && $purchaseOrder->outlet_id !== null) {
                if ($newStatus === 'partially_received') {
                    $this->purchaseAuditService->logPurchaseOrderLifecycle('partially_received', (int) $purchaseOrder->id, (int) $purchaseOrder->outlet_id, $actor, [
                        'number' => $purchaseOrder->number,
                        'completionPercentage' => $progress['completionPercentage'],
                    ]);
                } elseif ($newStatus === 'received') {
                    $this->purchaseAuditService->logPurchaseOrderLifecycle('received', (int) $purchaseOrder->id, (int) $purchaseOrder->outlet_id, $actor, [
                        'number' => $purchaseOrder->number,
                        'completionPercentage' => $progress['completionPercentage'],
                    ]);
                }
            }
        }

        return $purchaseOrder->fresh()->load(['items', 'purchaseRequest', 'goodsReceivingNotes']);
    }

    /** @return array{totalOrderedQty:float,totalReceivedQty:float,totalRemainingQty:float,completionPercentage:float,items:array<int,array<string,mixed>>} */
    public function calculateProgress(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing('items');

        $totalOrdered = 0.0;
        $totalReceived = 0.0;
        $items = [];

        foreach ($purchaseOrder->items as $item) {
            $ordered = (float) $item->ordered_qty;
            $received = (float) $item->received_qty;
            $remaining = max(0, $ordered - $received);
            $totalOrdered += $ordered;
            $totalReceived += $received;
            $items[] = [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->ingredient_id,
                'orderedQty' => $ordered,
                'receivedQty' => $received,
                'remainingQty' => $remaining,
            ];
        }

        $totalRemaining = max(0, $totalOrdered - $totalReceived);
        $completionPercentage = $totalOrdered > 0
            ? round(($totalReceived / $totalOrdered) * 100, 2)
            : 0.0;

        return [
            'totalOrderedQty' => $totalOrdered,
            'totalReceivedQty' => $totalReceived,
            'totalRemainingQty' => $totalRemaining,
            'completionPercentage' => $completionPercentage,
            'items' => $items,
        ];
    }

    private function assertOutlet(User $actor, PurchaseOrder $purchaseOrder): void
    {
        abort_if(
            $purchaseOrder->outlet_id === null || (int) $purchaseOrder->outlet_id < 1,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Purchase order outlet_id is required.'
        );
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseOrder->outlet_id);
    }
}
