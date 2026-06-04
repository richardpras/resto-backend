<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'overtime_type_id',
        'overtime_date',
        'start_time',
        'end_time',
        'total_minutes',
        'total_hours',
        'reason',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'total_hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overtimeType(): BelongsTo
    {
        return $this->belongsTo(OvertimeType::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Modules\Settings\Domain\Outlet::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
