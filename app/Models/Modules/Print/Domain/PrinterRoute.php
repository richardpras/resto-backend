<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterRoute extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'printer_profile_id',
        'print_type',
        'route_scope',
        'item_id',
        'station',
        'category',
        'priority',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }
}
