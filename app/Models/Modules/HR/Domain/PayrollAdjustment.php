<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const CATEGORY_BONUS = 'bonus';

    public const CATEGORY_INCENTIVE = 'incentive';

    public const CATEGORY_COMMISSION = 'commission';

    public const CATEGORY_ALLOWANCE = 'allowance';

    public const CATEGORY_PENALTY = 'penalty';

    public const CATEGORY_CORRECTION = 'correction';

    public const CATEGORY_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CANCELLED = 'cancelled';

    public const CATEGORIES = [
        self::CATEGORY_BONUS,
        self::CATEGORY_INCENTIVE,
        self::CATEGORY_COMMISSION,
        self::CATEGORY_ALLOWANCE,
        self::CATEGORY_PENALTY,
        self::CATEGORY_CORRECTION,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'outlet_id',
        'employee_id',
        'adjustment_no',
        'type',
        'category',
        'amount',
        'effective_from',
        'effective_to',
        'status',
        'approved_by',
        'approved_at',
        'description',
    ];

    protected $casts = [
        'amount' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
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
}
