<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;

final class ProcurementPostingStatusService
{
    public function __construct(
        private readonly ProcurementPostingService $procurementPostingService,
    ) {}

    /** @return array<string,mixed> */
    public function forGrn(GoodsReceivingNote $grn): array
    {
        $grn->loadMissing(['latestProcurementPosting.journal']);

        return $this->buildPayload(
            ProcurementPosting::SOURCE_GRN,
            (int) $grn->id,
            $grn->status === 'posted',
            $grn->latestProcurementPosting,
        );
    }

    /** @return array<string,mixed> */
    public function forInvoice(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing(['latestProcurementPosting.journal']);

        return $this->buildPayload(
            ProcurementPosting::SOURCE_INVOICE,
            (int) $invoice->id,
            in_array($invoice->status, ['approved', 'partially_paid', 'paid'], true),
            $invoice->latestProcurementPosting,
        );
    }

    /** @return array<string,mixed> */
    public function forPayment(SupplierPayment $payment): array
    {
        $payment->loadMissing(['latestProcurementPosting.journal']);

        return $this->buildPayload(
            ProcurementPosting::SOURCE_SUPPLIER_PAYMENT,
            (int) $payment->id,
            $payment->status === 'posted',
            $payment->latestProcurementPosting,
        );
    }

    /** @return array<string,mixed> */
    private function buildPayload(string $sourceType, int $sourceId, bool $eligible, ?ProcurementPosting $posting): array
    {
        if ($posting === null) {
            $posting = $this->procurementPostingService->getPostingStatus($sourceType, $sourceId);
            $posting?->loadMissing('journal');
        }

        if ($posting !== null && $posting->status === ProcurementPosting::STATUS_POSTED) {
            return [
                'status' => 'posted',
                'journalEntryId' => $posting->journal_entry_id !== null ? (string) $posting->journal_entry_id : null,
                'journalNo' => $posting->journal?->journal_no,
                'postedAt' => optional($posting->posted_at)->toISOString(),
                'reason' => null,
            ];
        }

        if ($posting !== null && $posting->status === ProcurementPosting::STATUS_REVERSED) {
            return [
                'status' => 'reversed',
                'journalEntryId' => $posting->journal_entry_id !== null ? (string) $posting->journal_entry_id : null,
                'journalNo' => $posting->journal?->journal_no,
                'postedAt' => optional($posting->posted_at)->toISOString(),
                'reversedAt' => optional($posting->reversed_at)->toISOString(),
                'reason' => 'Posting reversed',
            ];
        }

        if (! $eligible) {
            return [
                'status' => 'not_posted',
                'journalEntryId' => null,
                'journalNo' => null,
                'postedAt' => null,
                'reason' => 'Document not eligible for accounting posting',
            ];
        }

        return [
            'status' => 'not_posted',
            'journalEntryId' => null,
            'journalNo' => null,
            'postedAt' => null,
            'reason' => $this->latestFailureReason($sourceId) ?? 'Not yet posted to accounting',
        ];
    }

    private function latestFailureReason(int $sourceId): ?string
    {
        $log = PosEventLog::query()
            ->where('event_type', 'posting_failed')
            ->where('entity_id', $sourceId)
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            return null;
        }

        $message = is_array($log->payload) ? ($log->payload['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : null;
    }
}
