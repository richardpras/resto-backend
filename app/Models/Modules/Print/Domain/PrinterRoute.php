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
        'production_station_id',
        'station_code',
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

    public function productionStation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Modules\Production\Domain\ProductionStation::class, 'production_station_id');
    }
}
