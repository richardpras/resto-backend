<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OvertimeType extends Model
{
    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'multiplier',
        'is_active',
    ];

    protected $casts = [
        'multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }
}
