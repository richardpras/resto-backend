<?php

namespace App\Modules\System\Http\Resources;

use App\Models\Modules\System\Domain\BugReportAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BugReportAttachment */
class BugReportAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bugReportId' => (int) $this->bug_report_id,
            'fileType' => $this->file_type,
            'fileSize' => (int) $this->file_size,
            'createdAt' => $this->created_at?->toIso8601String(),
            'downloadUrl' => '/bug-reports/'.$this->bug_report_id.'/attachments/'.$this->id,
        ];
    }
}
