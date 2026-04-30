<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    protected $fillable = [
        'tenant_id',
        'employee_id',
        'period_start',
        'period_end',
        'base_amount',
        'adjustment_amount',
        'deduction_amount',
        'net_amount',
        'status',
        'journal_id',
        'adjustments',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'base_amount' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'adjustments' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
