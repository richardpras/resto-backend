<?php

namespace App\Modules\Purchase\Http\Resources;

use App\Modules\Purchase\Services\ProcurementPostingStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'paymentNo' => $this->payment_no,
            'supplierId' => (string) $this->supplier_id,
            'supplierName' => $this->supplier?->name,
            'outletId' => $this->outlet_id ? (string) $this->outlet_id : null,
            'paymentDate' => optional($this->payment_date)->format('Y-m-d'),
            'paymentMethod' => $this->payment_method,
            'bankAccountId' => $this->bank_account_id,
            'referenceNo' => $this->reference_no,
            'notes' => $this->notes,
            'amount' => (float) $this->amount,
            'allocatedAmount' => (float) $this->allocated_amount,
            'unallocatedAmount' => (float) $this->unallocated_amount,
            'status' => $this->status,
            'approvedAt' => optional($this->approved_at)->toISOString(),
            'postedAt' => optional($this->posted_at)->toISOString(),
            'voidedAt' => optional($this->voided_at)->toISOString(),
            'allocations' => $this->allocations->map(static fn ($row): array => [
                'id' => (string) $row->id,
                'invoiceId' => (string) $row->purchase_invoice_id,
                'invoiceNumber' => $row->purchaseInvoice?->number,
                'allocatedAmount' => (float) $row->allocated_amount,
            ])->values(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'postingStatus' => app(ProcurementPostingStatusService::class)->forPayment($this->resource),
        ];
    }
}
