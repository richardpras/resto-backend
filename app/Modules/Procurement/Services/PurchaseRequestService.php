<?php

namespace App\Modules\Procurement\Services;

use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Modules\Purchase\Domain\PurchaseOrderItem;
use App\Models\User;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\PurchaseRequestItem;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use App\Modules\Purchase\Services\ProcurementMasterService;
use App\Modules\Purchase\Services\PurchaseAuditService;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseRequestService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ProcurementMasterService $procurementMasterService,
        private readonly ApprovalNotificationService $approvalNotificationService,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): PurchaseRequest
    {
        $outletId = $this->purchaseScopeService->resolveOutletId($actor, $data['outletId'] ?? $this->purchaseScopeService->requestedOutletIdFromRequest());

        return DB::transaction(function () use ($actor, $data, $outletId): PurchaseRequest {
            $row = PurchaseRequest::query()->create([
                'request_no' => $this->nextRequestNumber(),
                'outlet_id' => $outletId,
                'requested_by' => (string) ($data['requestedBy'] ?? $actor->name ?? $actor->email),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $this->createItem($row, $item);
            }

            $fresh = $row->fresh()->load(['items', 'outlet']);
            $this->purchaseAuditService->logPurchaseRequest('created', (int) $fresh->id, $outletId, $actor, [
                'requestNo' => $fresh->request_no,
                'status' => $fresh->status,
            ]);

            return $fresh;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(PurchaseRequest $purchaseRequest, User $actor, array $data): PurchaseRequest
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft purchase requests can be edited.');

        return DB::transaction(function () use ($purchaseRequest, $data): PurchaseRequest {
            if (array_key_exists('notes', $data)) {
                $purchaseRequest->notes = $data['notes'];
            }
            if (array_key_exists('requestedBy', $data)) {
                $purchaseRequest->requested_by = (string) $data['requestedBy'];
            }
            $purchaseRequest->save();

            if (array_key_exists('items', $data)) {
                $purchaseRequest->items()->delete();
                foreach ($data['items'] as $item) {
                    $this->createItem($purchaseRequest, $item);
                }
            }

            return $purchaseRequest->fresh()->load(['items', 'outlet']);
        });
    }

    public function submit(PurchaseRequest $purchaseRequest, User $actor): PurchaseRequest
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft purchase requests can be submitted.');
        $purchaseRequest->loadCount('items');
        abort_if($purchaseRequest->items_count < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot submit a purchase request without items.');

        return DB::transaction(function () use ($purchaseRequest, $actor): PurchaseRequest {
            $purchaseRequest->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $fresh = $purchaseRequest->fresh()->load(['items', 'outlet']);
            $this->purchaseAuditService->logPurchaseRequest('submitted', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'requestNo' => $fresh->request_no,
            ]);
            $this->approvalNotificationService->purchaseRequestSubmitted($fresh, $actor);

            return $fresh;
        });
    }

    public function approve(PurchaseRequest $purchaseRequest, User $actor): PurchaseRequest
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status !== 'submitted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only submitted purchase requests can be approved.');

        return DB::transaction(function () use ($purchaseRequest, $actor): PurchaseRequest {
            $purchaseRequest->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $fresh = $purchaseRequest->fresh()->load(['items', 'outlet']);
            $this->purchaseAuditService->logPurchaseRequest('approved', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'requestNo' => $fresh->request_no,
                'approvedBy' => $actor->id,
            ]);
            $this->approvalNotificationService->purchaseRequestApproved($fresh, $actor);

            return $fresh;
        });
    }

    public function reject(PurchaseRequest $purchaseRequest, User $actor): PurchaseRequest
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status !== 'submitted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only submitted purchase requests can be rejected.');

        return DB::transaction(function () use ($purchaseRequest, $actor): PurchaseRequest {
            $purchaseRequest->update([
                'status' => 'rejected',
                'rejected_at' => now(),
            ]);

            $fresh = $purchaseRequest->fresh()->load(['items', 'outlet']);
            $this->purchaseAuditService->logPurchaseRequest('rejected', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'requestNo' => $fresh->request_no,
            ]);
            $this->approvalNotificationService->purchaseRequestRejected($fresh, $actor);

            return $fresh;
        });
    }

    public function cancel(PurchaseRequest $purchaseRequest, User $actor): PurchaseRequest
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status === 'converted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Converted purchase requests cannot be cancelled.');
        abort_if(! in_array($purchaseRequest->status, ['draft', 'submitted'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft or submitted purchase requests can be cancelled.');

        return DB::transaction(function () use ($purchaseRequest, $actor): PurchaseRequest {
            $purchaseRequest->update([
                'status' => 'cancelled',
            ]);

            $fresh = $purchaseRequest->fresh()->load(['items', 'outlet']);
            $this->purchaseAuditService->logPurchaseRequest('cancelled', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'requestNo' => $fresh->request_no,
            ]);

            return $fresh;
        });
    }

    /** @param array<string,mixed> $data */
    public function convertToPurchaseOrder(PurchaseRequest $purchaseRequest, User $actor, array $data): PurchaseOrder
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $purchaseRequest->outlet_id);
        abort_if($purchaseRequest->status !== 'approved', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved purchase requests can be converted to a purchase order.');
        abort_if(
            PurchaseOrder::query()->where('purchase_request_id', $purchaseRequest->id)->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This purchase request has already been converted to a purchase order.'
        );

        $supplierId = (int) $data['supplierId'];
        $this->procurementMasterService->validateSupplier($supplierId);

        return DB::transaction(function () use ($purchaseRequest, $actor, $supplierId): PurchaseOrder {
            $purchaseRequest->load('items');

            $po = PurchaseOrder::query()->create([
                'outlet_id' => $purchaseRequest->outlet_id,
                'number' => $this->nextPoNumber(),
                'purchase_request_id' => $purchaseRequest->id,
                'source_pr_id' => $purchaseRequest->id,
                'supplier_id' => $supplierId,
                'order_date' => now()->toDateString(),
                'status' => 'draft',
                'notes' => $purchaseRequest->notes,
            ]);

            foreach ($purchaseRequest->items as $item) {
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $po->id,
                    'pr_item_id' => $item->id,
                    'ingredient_id' => (int) $item->inventory_item_id,
                    'ordered_qty' => (float) $item->quantity,
                    'requested_qty' => (float) $item->quantity,
                    'is_from_pr' => true,
                    'received_qty' => 0,
                    'unit_price' => (float) ($item->estimated_cost ?? 0),
                ]);
            }

            $purchaseRequest->update([
                'status' => 'converted',
            ]);

            $this->purchaseAuditService->logPurchaseOrder('created', (int) $po->id, (int) $po->outlet_id, $actor, [
                'number' => $po->number,
                'supplierId' => $po->supplier_id,
                'status' => $po->status,
                'purchaseRequestId' => $purchaseRequest->id,
                'fromConversion' => true,
            ]);

            $this->purchaseAuditService->logPurchaseRequest('converted', (int) $purchaseRequest->id, (int) $purchaseRequest->outlet_id, $actor, [
                'requestNo' => $purchaseRequest->request_no,
                'purchaseOrderId' => $po->id,
                'purchaseOrderNumber' => $po->number,
            ]);

            return $po->fresh()->load(['items', 'purchaseRequest']);
        });
    }

    /** @param array<string,mixed> $item */
    private function createItem(PurchaseRequest $purchaseRequest, array $item): PurchaseRequestItem
    {
        return PurchaseRequestItem::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'inventory_item_id' => (int) $item['inventoryItemId'],
            'quantity' => (float) $item['quantity'],
            'unit' => $item['unit'] ?? null,
            'estimated_cost' => array_key_exists('estimatedCost', $item) && $item['estimatedCost'] !== null
                ? (float) $item['estimatedCost']
                : null,
            'notes' => $item['notes'] ?? null,
        ]);
    }

    private function nextRequestNumber(): string
    {
        $lastId = (int) (PurchaseRequest::query()->max('id') ?? 0);

        return 'PR-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }

    private function nextPoNumber(): string
    {
        $lastId = (int) (PurchaseOrder::query()->max('id') ?? 0);

        return 'PO-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
