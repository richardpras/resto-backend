<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPreparationPeriod extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'outlet_id',
        'period_start',
        'period_end',
        'status',
        'approved_by',
        'approved_at',
        'locked_by',
        'locked_at',
        'generated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'locked_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(PayrollPreparationSnapshot::class, 'preparation_period_id');
    }
}
