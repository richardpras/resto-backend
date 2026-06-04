<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeLoan extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'loan_no',
        'principal_amount',
        'installment_amount',
        'total_installments',
        'paid_installments',
        'remaining_balance',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'principal_amount' => 'float',
        'installment_amount' => 'float',
        'remaining_balance' => 'float',
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
        return $this->hasMany(EmployeeLoanInstallment::class, 'loan_id');
    }
}
