<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'requires_attachment',
        'deduct_leave_balance',
        'paid_leave',
        'is_active',
    ];

    protected $casts = [
        'requires_attachment' => 'boolean',
        'deduct_leave_balance' => 'boolean',
        'paid_leave' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalance::class);
    }
}
