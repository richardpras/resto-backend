<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Employee extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'employee_no',
        'full_name',
        'email',
        'phone',
        'position',
        'outlet',
        'salary_type',
        'base_salary',
        'overtime_rate',
        'hire_date',
        'termination_date',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'base_salary' => 'decimal:2',
        'overtime_rate' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
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
