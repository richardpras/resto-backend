<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPreparationSnapshot extends Model
{
    protected $fillable = [
        'preparation_period_id',
        'employee_id',
        'scheduled_days',
        'attended_days',
        'absent_days',
        'late_minutes',
        'early_leave_minutes',
        'leave_days',
        'paid_leave_days',
        'unpaid_leave_days',
        'overtime_minutes',
        'overtime_hours',
        'review_required',
        'snapshot_json',
    ];

    protected $casts = [
        'leave_days' => 'float',
        'paid_leave_days' => 'float',
        'unpaid_leave_days' => 'float',
        'overtime_hours' => 'float',
        'review_required' => 'boolean',
        'snapshot_json' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPreparationPeriod::class, 'preparation_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
