<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'period',
        'outlet',
        'status',
        'paid_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
