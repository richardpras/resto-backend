<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceReview extends Model
{
    public const TYPE_APPROVED = 'approved';

    public const TYPE_CORRECTED = 'corrected';

    public const TYPE_EXCUSED_ABSENCE = 'excused_absence';

    public const TYPE_IGNORED = 'ignored';

    protected $fillable = [
        'attendance_summary_id',
        'reviewer_id',
        'review_type',
        'notes',
        'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function summary(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailySummary::class, 'attendance_summary_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
