<?php

namespace App\Modules\Purchase\Http\Resources;

use App\Modules\Purchase\Services\ProcurementPostingStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $receivedValue = $this->items->sum(
            static fn ($item): float => (float) $item->received_qty * (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0)
        );

        return [
            'id' => (string) $this->id,
            'grnNumber' => $this->number,
            'poReference' => $this->purchaseOrder?->number,
            'purchaseOrderId' => $this->purchase_order_id ? (string) $this->purchase_order_id : null,
            'warehouseId' => $this->warehouse_id ? (string) $this->warehouse_id : null,
            'destinationWarehouseId' => $this->destination_warehouse_id ? (string) $this->destination_warehouse_id : null,
            'date' => optional($this->received_date)->format('Y-m-d'),
            'status' => $this->status ?? 'draft',
            'notes' => $this->notes,
            'supplierDeliveryNo' => $this->supplier_delivery_no,
            'supplierDeliveryDate' => optional($this->supplier_delivery_date)->format('Y-m-d'),
            'vehicleNo' => $this->vehicle_no,
            'driverName' => $this->driver_name,
            'receivedBy' => $this->received_by,
            'receivedAt' => optional($this->received_at)->toISOString(),
            'postedAt' => optional($this->posted_at)->toISOString(),
            'cancelledAt' => optional($this->cancelled_at)->toISOString(),
            'receivedValue' => round($receivedValue, 2),
            'relatedInvoiceCount' => $this->relationLoaded('invoices')
                ? $this->invoices->where('status', '!=', 'void')->count()
                : ($this->invoice ? 1 : 0),
            'items' => $this->items->map(static fn ($item): array => [
                'id' => (string) $item->id,
                'inventoryItemId' => (string) $item->ingredient_id,
                'ingredientName' => $item->relationLoaded('ingredient') ? $item->ingredient?->name : null,
                'orderedQty' => (float) ($item->purchaseOrderItem?->ordered_qty ?? 0),
                'receivedQty' => (float) $item->received_qty,
                'unitCost' => (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0),
                'unit' => null,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'postingStatus' => app(ProcurementPostingStatusService::class)->forGrn($this->resource),
        ];
    }
}
