<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->purchaseOrder?->items ?? collect();
        $paidAmount = (float) $this->payments->sum('amount');
        $total = (float) $this->total;

        return [
            'id' => (string) $this->id,
            'invoiceNumber' => $this->number,
            'supplierId' => $this->purchaseOrder?->supplier_id ? (string) $this->purchaseOrder->supplier_id : '',
            'poReference' => $this->purchaseOrder?->number,
            'grReference' => $this->goodsReceivingNote?->number,
            'purchaseOrderId' => $this->purchase_order_id ? (string) $this->purchase_order_id : null,
            'goodsReceiptId' => $this->goods_receiving_note_id ? (string) $this->goods_receiving_note_id : null,
            'date' => optional($this->invoice_date)->format('Y-m-d'),
            'status' => $this->status ?? 'unpaid',
            'total' => $total,
            'paidAmount' => $paidAmount,
            'remainingAmount' => max(0, $total - $paidAmount),
            'tax' => (float) ($this->tax ?? 0),
            'items' => $items->map(static fn ($item): array => [
                'inventoryItemId' => (string) $item->ingredient_id,
                'qty' => (float) $item->ordered_qty,
                'unit' => '',
                'price' => (float) $item->unit_price,
            ])->values(),
            'payments' => $this->payments->map(static fn ($payment): array => [
                'id' => (string) $payment->id,
                'date' => optional($payment->payment_date)->format('Y-m-d'),
                'amount' => (float) $payment->amount,
                'paymentMethod' => $payment->payment_method,
                'referenceNo' => $payment->reference_no,
                'notes' => $payment->notes,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }
}
