<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveBalance extends Model
{
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'allocated_days',
        'used_days',
        'remaining_days',
    ];

    protected $casts = [
        'allocated_days' => 'decimal:2',
        'used_days' => 'decimal:2',
        'remaining_days' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
