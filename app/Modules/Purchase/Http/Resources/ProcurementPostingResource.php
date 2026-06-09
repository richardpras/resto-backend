<?php

namespace App\Modules\Purchase\Http\Resources;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $document = $this->resolveDocument();

        return [
            'id' => (string) $this->id,
            'postingNo' => $this->posting_no,
            'outletId' => $this->outlet_id !== null ? (string) $this->outlet_id : null,
            'sourceType' => $this->source_type,
            'sourceId' => (string) $this->source_id,
            'documentNo' => $document['documentNo'] ?? null,
            'supplierName' => $document['supplierName'] ?? null,
            'amount' => (float) $this->amount,
            'journalEntryId' => $this->journal_entry_id !== null ? (string) $this->journal_entry_id : null,
            'journalNo' => $this->journal?->journal_no,
            'status' => $this->status,
            'postedAt' => optional($this->posted_at)->toISOString(),
            'postedBy' => $this->posted_by !== null ? (string) $this->posted_by : null,
            'reversedAt' => optional($this->reversed_at)->toISOString(),
            'reversedBy' => $this->reversed_by !== null ? (string) $this->reversed_by : null,
            'notes' => $this->notes,
            'createdAt' => optional($this->created_at)->toISOString(),
        ];
    }

    /** @return array{documentNo:?string,supplierName:?string} */
    private function resolveDocument(): array
    {
        return match ($this->source_type) {
            'grn' => $this->resolveGrnDocument(),
            'invoice' => $this->resolveInvoiceDocument(),
            'supplier_payment' => $this->resolvePaymentDocument(),
            default => ['documentNo' => null, 'supplierName' => null],
        };
    }

    /** @return array{documentNo:?string,supplierName:?string} */
    private function resolveGrnDocument(): array
    {
        $grn = GoodsReceivingNote::query()->with('purchaseOrder.supplier')->find($this->source_id);

        return [
            'documentNo' => $grn?->number,
            'supplierName' => $grn?->purchaseOrder?->supplier?->name,
        ];
    }

    /** @return array{documentNo:?string,supplierName:?string} */
    private function resolveInvoiceDocument(): array
    {
        $invoice = PurchaseInvoice::query()->with('supplier', 'purchaseOrder.supplier')->find($this->source_id);

        return [
            'documentNo' => $invoice?->number,
            'supplierName' => $invoice?->supplier?->name ?? $invoice?->purchaseOrder?->supplier?->name,
        ];
    }

    /** @return array{documentNo:?string,supplierName:?string} */
    private function resolvePaymentDocument(): array
    {
        $payment = SupplierPayment::query()->with('supplier')->find($this->source_id);

        return [
            'documentNo' => $payment?->payment_no,
            'supplierName' => $payment?->supplier?->name,
        ];
    }
}
