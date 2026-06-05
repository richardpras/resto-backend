<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeReimbursementAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeReimbursementAttachment */
class EmployeeReimbursementAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reimbursementId' => (int) $this->reimbursement_id,
            'fileName' => $this->file_name,
            'filePath' => $this->file_path,
            'fileSize' => (int) $this->file_size,
            'mimeType' => $this->mime_type,
            'createdAt' => $this->created_at?->toDateTimeString(),
        ];
    }
}
