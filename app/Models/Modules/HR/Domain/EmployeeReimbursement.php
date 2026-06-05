<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeReimbursement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PAID = 'paid';

    public const CATEGORY_TRANSPORT = 'transport';

    public const CATEGORY_FUEL = 'fuel';

    public const CATEGORY_MEAL = 'meal';

    public const CATEGORY_MEDICAL = 'medical';

    public const CATEGORY_COMMUNICATION = 'communication';

    public const CATEGORY_PURCHASE = 'purchase';

    public const CATEGORY_ENTERTAINMENT = 'entertainment';

    public const CATEGORY_TRAINING = 'training';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_TRANSPORT,
        self::CATEGORY_FUEL,
        self::CATEGORY_MEAL,
        self::CATEGORY_MEDICAL,
        self::CATEGORY_COMMUNICATION,
        self::CATEGORY_PURCHASE,
        self::CATEGORY_ENTERTAINMENT,
        self::CATEGORY_TRAINING,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'claim_no',
        'category',
        'title',
        'description',
        'claim_amount',
        'expense_date',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'paid_at',
        'approved_by',
        'rejected_by',
        'payroll_run_item_id',
        'notes',
    ];

    protected $casts = [
        'claim_amount' => 'float',
        'expense_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmployeeReimbursementAttachment::class, 'reimbursement_id');
    }

    public function payrollRunItem(): BelongsTo
    {
        return $this->belongsTo(PayrollRunItemV2::class, 'payroll_run_item_id');
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
