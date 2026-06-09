<?php

namespace App\Modules\Accounting\Http\Resources;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountingPostingFailure */
class AccountingPostingFailureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sourceType' => $this->source_type,
            'sourceId' => (string) $this->source_id,
            'outletId' => $this->outlet_id !== null ? (string) $this->outlet_id : null,
            'errorCode' => $this->error_code,
            'errorMessage' => $this->error_message,
            'payloadJson' => $this->payload_json,
            'status' => $this->status,
            'journalId' => $this->journal_id !== null ? (string) $this->journal_id : null,
            'journalNo' => $this->relationLoaded('journal') ? $this->journal?->journal_no : null,
            'createdAt' => optional($this->created_at)->toISOString(),
            'resolvedAt' => optional($this->resolved_at)->toISOString(),
        ];
    }
}
