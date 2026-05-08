<?php

namespace App\Modules\Print\Http\Resources;

use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReceiptRenderHistory
 */
class ReceiptRenderHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('fiscalInvoice');

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'receiptTemplateId' => $this->receipt_template_id !== null ? (int) $this->receipt_template_id : null,
            'kind' => (string) $this->kind,
            'sourceType' => (string) $this->source_type,
            'sourceId' => (int) $this->source_id,
            'orderSplitId' => $this->order_split_id !== null ? (int) $this->order_split_id : null,
            'thermalText' => (string) $this->thermal_text,
            'htmlSnapshot' => $this->html_snapshot,
            'pdfAvailable' => $this->pdf_storage_path !== null,
            'invoiceNumber' => $this->resource->fiscalInvoice !== null
                ? (string) $this->resource->fiscalInvoice->invoice_number
                : null,
            'fiscalInvoiceId' => $this->fiscal_invoice_id !== null ? (int) $this->fiscal_invoice_id : null,
            'reprintCount' => (int) $this->reprint_count,
            'deferredReplayPending' => (bool) $this->deferred_replay_pending,
            'recoveryMeta' => $this->recovery_meta,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
