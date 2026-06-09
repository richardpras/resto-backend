<?php

namespace App\Modules\Purchase\Http\Resources;

use App\Modules\Purchase\Services\ProcurementPostingStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paidAmount = (float) ($this->paid_amount > 0 ? $this->paid_amount : $this->payments->sum('amount'));
        $totalAmount = (float) ($this->total_amount > 0 ? $this->total_amount : $this->total);
        $outstanding = (float) ($this->outstanding_amount > 0 || $this->status === 'void'
            ? $this->outstanding_amount
            : max(0, $totalAmount - $paidAmount));

        return [
            'id' => (string) $this->id,
            'invoiceNumber' => $this->number,
            'supplierInvoiceNo' => $this->supplier_invoice_no,
            'supplierId' => $this->supplier_id
                ? (string) $this->supplier_id
                : ($this->purchaseOrder?->supplier_id ? (string) $this->purchaseOrder->supplier_id : ''),
            'poReference' => $this->purchaseOrder?->number,
            'grReference' => $this->goodsReceivingNote?->number,
            'purchaseOrderId' => $this->purchase_order_id ? (string) $this->purchase_order_id : null,
            'goodsReceiptId' => $this->goods_receiving_note_id ? (string) $this->goods_receiving_note_id : null,
            'date' => optional($this->invoice_date)->format('Y-m-d'),
            'dueDate' => optional($this->due_date)->format('Y-m-d'),
            'status' => $this->mapStatusForApi($this->status ?? 'draft'),
            'subtotal' => (float) ($this->subtotal ?? 0),
            'tax' => (float) ($this->tax_amount ?? $this->tax ?? 0),
            'taxPercentage' => $this->tax_percentage !== null ? (float) $this->tax_percentage : null,
            'discountAmount' => (float) ($this->discount_amount ?? 0),
            'total' => $totalAmount,
            'paidAmount' => $paidAmount,
            'remainingAmount' => $outstanding,
            'outstandingAmount' => $outstanding,
            'notes' => $this->notes,
            'approvedAt' => optional($this->approved_at)->toISOString(),
            'matchStatus' => $this->latestMatchResult?->match_status,
            'matchQtyDifference' => $this->latestMatchResult !== null ? (float) $this->latestMatchResult->qty_difference : null,
            'matchPriceDifference' => $this->latestMatchResult !== null ? (float) $this->latestMatchResult->price_difference : null,
            'matchAmountDifference' => $this->latestMatchResult !== null ? (float) $this->latestMatchResult->amount_difference : null,
            'postingStatus' => app(ProcurementPostingStatusService::class)->forInvoice($this->resource),
            'items' => $this->items->map(static fn ($item): array => [
                'inventoryItemId' => (string) $item->ingredient_id,
                'receivedQty' => (float) ($item->received_qty ?? 0),
                'invoicedQty' => (float) ($item->invoiced_qty ?? $item->qty ?? 0),
                'qty' => (float) ($item->invoiced_qty ?? $item->qty ?? 0),
                'unitCost' => (float) ($item->unit_cost ?? $item->unit_price ?? 0),
                'lineSubtotal' => (float) ($item->line_subtotal ?? 0),
                'lineTotal' => (float) ($item->line_total ?? 0),
                'unit' => '',
                'price' => (float) ($item->unit_cost ?? $item->unit_price ?? 0),
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

    private function mapStatusForApi(?string $status): string
    {
        return match ($status) {
            'partially_paid' => 'partial',
            'void' => 'void',
            default => $status ?? 'draft',
        };
    }
}
