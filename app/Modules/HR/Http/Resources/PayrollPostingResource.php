<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\PayrollPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollPosting */
class PayrollPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $journal = $this->relationLoaded('journal') ? $this->journal : null;
        $run = $this->relationLoaded('payrollRun') ? $this->payrollRun : null;

        return [
            'id' => (int) $this->id,
            'payrollRunId' => (int) $this->payroll_run_id,
            'journalEntryId' => $this->journal_entry_id !== null ? (int) $this->journal_entry_id : null,
            'postingStatus' => $this->posting_status,
            'postedAt' => $this->posted_at?->toIso8601String(),
            'reversedAt' => $this->reversed_at?->toIso8601String(),
            'notes' => $this->notes,
            'journal' => $journal ? [
                'id' => (int) $journal->id,
                'journalNo' => $journal->journal_no,
                'status' => $journal->status,
                'journalDate' => $journal->journal_date?->toDateString(),
            ] : null,
            'payrollRun' => $run ? [
                'id' => (int) $run->id,
                'status' => $run->status,
            ] : null,
        ];
    }
}
