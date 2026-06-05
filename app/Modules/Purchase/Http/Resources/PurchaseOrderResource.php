<?php

namespace App\Modules\Purchase\Http\Resources;

use App\Modules\Purchase\Services\PurchaseOrderLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrderLifecycleService $lifecycle */
        $lifecycle = app(PurchaseOrderLifecycleService::class);
        $progress = $lifecycle->calculateProgress($this->resource);

        return [
            'id' => (string) $this->id,
            'poNumber' => $this->number,
            'supplierId' => (string) $this->supplier_id,
            'destinationWarehouseId' => $this->destination_warehouse_id ? (string) $this->destination_warehouse_id : null,
            'date' => optional($this->order_date)->format('Y-m-d'),
            'referencePR' => $this->purchaseRequest?->request_no,
            'purchaseRequestId' => $this->purchase_request_id ? (string) $this->purchase_request_id : null,
            'sourcePrId' => $this->source_pr_id ? (string) $this->source_pr_id : null,
            'sourceType' => $this->purchase_request_id ? 'PR' : 'DIRECT',
            'status' => $this->status,
            'notes' => $this->notes,
            'submittedAt' => optional($this->submitted_at)->toISOString(),
            'submittedBy' => $this->submitted_by ? (string) $this->submitted_by : null,
            'approvedAt' => optional($this->approved_at)->toISOString(),
            'approvedBy' => $this->approved_by ? (string) $this->approved_by : null,
            'cancelledAt' => optional($this->cancelled_at)->toISOString(),
            'cancelledBy' => $this->cancelled_by ? (string) $this->cancelled_by : null,
            'closedAt' => optional($this->closed_at)->toISOString(),
            'closedBy' => $this->closed_by ? (string) $this->closed_by : null,
            'totalOrderedQty' => $progress['totalOrderedQty'],
            'totalReceivedQty' => $progress['totalReceivedQty'],
            'totalRemainingQty' => $progress['totalRemainingQty'],
            'completionPercentage' => $progress['completionPercentage'],
            'items' => $this->items->map(static function ($item) use ($progress): array {
                $line = collect($progress['items'])->firstWhere('id', (string) $item->id);

                return [
                    'id' => (string) $item->id,
                    'inventoryItemId' => (string) $item->ingredient_id,
                    'qty' => (float) $item->ordered_qty,
                    'orderedQty' => (float) $item->ordered_qty,
                    'prItemId' => $item->pr_item_id ? (string) $item->pr_item_id : null,
                    'requestedQty' => (float) ($item->requested_qty ?? 0),
                    'isFromPr' => (bool) ($item->is_from_pr ?? false),
                    'unit' => null,
                    'price' => (float) $item->unit_price,
                    'receivedQty' => (float) $item->received_qty,
                    'remainingQty' => (float) ($line['remainingQty'] ?? max(0, (float) $item->ordered_qty - (float) $item->received_qty)),
                ];
            })->values(),
            'goodsReceipts' => $this->whenLoaded('goodsReceivingNotes', fn () => $this->goodsReceivingNotes->map(static fn ($grn): array => [
                'id' => (string) $grn->id,
                'grnNumber' => $grn->number,
                'date' => optional($grn->received_date)->format('Y-m-d'),
            ])->values()),
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }
}
