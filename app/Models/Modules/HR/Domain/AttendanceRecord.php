<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_EARLY_LEAVE = 'early_leave';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const SOURCE_FINGERPRINT = 'fingerprint';

    public const SOURCE_CSV_IMPORT = 'csv_import';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'roster_id',
        'shift_id',
        'attendance_date',
        'clock_in',
        'clock_out',
        'worked_minutes',
        'status',
        'source',
        'notes',
        'import_batch_id',
        'updated_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'clock_in' => 'datetime',
        'clock_out' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(EmployeeRoster::class, 'roster_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(AttendanceImportBatch::class, 'import_batch_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
