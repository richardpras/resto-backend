<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeCashAdvance extends Model
{
    public const REPAYMENT_NEXT_PAYROLL = 'next_payroll';

    public const REPAYMENT_INSTALLMENT = 'installment';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'advance_no',
        'amount',
        'repayment_type',
        'installment_count',
        'installment_amount',
        'deducted_amount',
        'remaining_amount',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'installment_amount' => 'float',
        'deducted_amount' => 'float',
        'remaining_amount' => 'float',
        'approved_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(EmployeeCashAdvanceInstallment::class, 'cash_advance_id');
    }
}
