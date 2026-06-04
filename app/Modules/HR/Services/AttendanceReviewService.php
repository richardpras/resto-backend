<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendanceReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AttendanceReviewService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly AttendanceSummaryQueryService $queryService,
        private readonly AttendancePeriodService $periodService,
    ) {}

    public function submitReview(?User $user, int $summaryId, array $payload): AttendanceDailySummary
    {
        $summary = $this->queryService->findAccessible($user, $summaryId);

        $this->periodService->assertCanReview(
            (int) $summary->outlet_id,
            $summary->attendance_date->toDateString(),
        );

        $reviewType = (string) ($payload['reviewType'] ?? '');
        $allowed = [
            AttendanceReview::TYPE_APPROVED,
            AttendanceReview::TYPE_CORRECTED,
            AttendanceReview::TYPE_EXCUSED_ABSENCE,
            AttendanceReview::TYPE_IGNORED,
        ];

        if (! in_array($reviewType, $allowed, true)) {
            throw ValidationException::withMessages([
                'reviewType' => ['Invalid review type.'],
            ]);
        }

        $now = now();

        AttendanceReview::query()->create([
            'attendance_summary_id' => $summary->id,
            'reviewer_id' => $user?->id,
            'review_type' => $reviewType,
            'notes' => $payload['notes'] ?? null,
            'reviewed_at' => $now,
        ]);

        $summary->update([
            'requires_review' => false,
            'reviewed_at' => $now,
        ]);

        if ($reviewType === AttendanceReview::TYPE_EXCUSED_ABSENCE && $summary->is_absent) {
            $summary->update([
                'attendance_status' => AttendanceDailySummary::STATUS_PRESENT,
                'is_absent' => false,
            ]);
        }

        return $summary->refresh()->load(['employee', 'shift', 'reviews.reviewer', 'attendanceRecord']);
    }
}
