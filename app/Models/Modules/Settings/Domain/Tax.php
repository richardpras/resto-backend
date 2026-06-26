<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'value',
        'apply_dine_in',
        'apply_takeaway',
        'inclusive',
        'status',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'apply_dine_in' => 'boolean',
        'apply_takeaway' => 'boolean',
        'inclusive' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function outletAssignments(): HasMany
    {
        return $this->hasMany(OutletTaxAssignment::class, 'tax_id');
    }
}
