<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\HR\Domain\AttendanceAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    protected $fillable = [
        'employee_id',
        'shift_id',
        'attendance_date',
        'check_in',
        'check_out',
        'source',
        'status',
        'sync_key',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AttendanceAuditLog::class);
    }
}
