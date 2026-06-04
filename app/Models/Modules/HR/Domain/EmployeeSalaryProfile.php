<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryProfile extends Model
{
    public const OVERTIME_RATE_FIXED_HOURLY = 'fixed_hourly';

    public const OVERTIME_RATE_MULTIPLIER_HOURLY = 'multiplier_hourly_salary';

    protected $fillable = [
        'employee_id',
        'basic_salary',
        'default_allowance',
        'default_deduction',
        'overtime_rate_type',
        'overtime_rate_value',
        'unpaid_leave_deduction_enabled',
        'attendance_deduction_enabled',
        'attendance_deduction_per_day',
    ];

    protected $casts = [
        'basic_salary' => 'float',
        'default_allowance' => 'float',
        'default_deduction' => 'float',
        'overtime_rate_value' => 'float',
        'unpaid_leave_deduction_enabled' => 'boolean',
        'attendance_deduction_enabled' => 'boolean',
        'attendance_deduction_per_day' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
