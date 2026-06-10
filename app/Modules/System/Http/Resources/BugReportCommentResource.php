<?php

namespace App\Modules\System\Http\Resources;

use App\Models\Modules\System\Domain\BugReportComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BugReportComment */
class BugReportCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'bugReportId' => (int) $this->bug_report_id,
            'userId' => (int) $this->user_id,
            'userName' => $this->relationLoaded('user') ? $this->user?->name : null,
            'comment' => $this->comment,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
