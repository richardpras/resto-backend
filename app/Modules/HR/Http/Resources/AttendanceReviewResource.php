<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\AttendanceReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendanceReview */
class AttendanceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reviewer = $this->relationLoaded('reviewer') ? $this->reviewer : null;

        return [
            'id' => (int) $this->id,
            'attendanceSummaryId' => (int) $this->attendance_summary_id,
            'reviewerId' => $this->reviewer_id,
            'reviewType' => $this->review_type,
            'notes' => $this->notes,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'reviewer' => $reviewer ? [
                'id' => (int) $reviewer->id,
                'name' => $reviewer->name,
                'email' => $reviewer->email,
            ] : null,
        ];
    }
}
