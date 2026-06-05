<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaxProfile extends Model
{
    protected $fillable = [
        'employee_id',
        'npwp_number',
        'ptkp_status',
        'pph21_enabled',
    ];

    protected $casts = [
        'pph21_enabled' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
