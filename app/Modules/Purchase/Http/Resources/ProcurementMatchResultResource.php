<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementMatchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'purchaseOrderId' => (string) $this->purchase_order_id,
            'goodsReceiptId' => (string) $this->goods_receipt_id,
            'invoiceId' => (string) $this->invoice_id,
            'poReference' => $this->purchaseOrder?->number,
            'grReference' => $this->goodsReceivingNote?->number,
            'invoiceNumber' => $this->purchaseInvoice?->number,
            'matchStatus' => $this->match_status,
            'qtyDifference' => (float) $this->qty_difference,
            'priceDifference' => (float) $this->price_difference,
            'amountDifference' => (float) $this->amount_difference,
            'matchedAt' => optional($this->matched_at)->toISOString(),
            'matchedBy' => $this->matched_by ? (string) $this->matched_by : null,
            'notes' => $this->notes,
        ];
    }
}
