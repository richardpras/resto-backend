<?php

namespace App\Models\Modules\UserManagement\Domain;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\Payroll;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Canonical HRM employee master record (single table for payroll, attendance, org structure).
 * HR module uses {@see \App\Models\Modules\HR\Domain\Employee} as a backward-compatible alias.
 */
class Employee extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_RESIGNED = 'resigned';

    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'outlet_id',
        'employee_no',
        'full_name',
        'email',
        'phone',
        'gender',
        'birth_date',
        'hire_date',
        'status',
        'position',
        'position_id',
        'department_id',
        'outlet',
        'salary_type',
        'base_salary',
        'overtime_rate',
        'termination_date',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'birth_date' => 'date',
        'termination_date' => 'date',
        'base_salary' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outletRelation(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function positionRelation(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(EmployeeRoster::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function shifts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Shift::class,
            Attendance::class,
            'employee_id',
            'id',
            'id',
            'shift_id'
        )->distinct();
    }
}
