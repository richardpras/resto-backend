<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeDailySummary extends Model
{
    protected $fillable = [
        'employee_id',
        'overtime_date',
        'approved_minutes',
        'approved_hours',
        'request_count',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'approved_hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
