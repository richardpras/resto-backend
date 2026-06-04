<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDailySummary extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_EARLY_LEAVE = 'early_leave';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'attendance_date',
        'scheduled_shift_id',
        'scheduled_start',
        'scheduled_end',
        'clock_in',
        'clock_out',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'is_absent',
        'is_incomplete',
        'requires_review',
        'attendance_status',
        'attendance_record_id',
        'reviewed_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
        'is_absent' => 'boolean',
        'is_incomplete' => 'boolean',
        'requires_review' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'scheduled_shift_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AttendanceReview::class, 'attendance_summary_id');
    }
}
