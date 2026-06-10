<?php

namespace App\Modules\System\Http\Resources;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BugReport */
class BugReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'reporterUserId' => (int) $this->reporter_user_id,
            'reporterName' => $this->relationLoaded('reporter') ? $this->reporter?->name : null,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'status' => $this->status,
            'currentRoute' => $this->current_route,
            'browser' => $this->browser,
            'userAgent' => $this->user_agent,
            'viewport' => $this->viewport,
            'appVersion' => $this->app_version,
            'diagnosticsJson' => $this->diagnostics_json,
            'assignedToUserId' => $this->assigned_to_user_id !== null ? (int) $this->assigned_to_user_id : null,
            'assigneeName' => $this->relationLoaded('assignee') ? $this->assignee?->name : null,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'attachments' => BugReportAttachmentResource::collection(
                $this->whenLoaded('attachments'),
            ),
            'comments' => BugReportCommentResource::collection(
                $this->whenLoaded('comments'),
            ),
        ];
    }
}
