<?php

namespace App\Models\Modules\Production\Domain;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionStation extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'code',
        'name',
        'type',
        'display_order',
        'is_active',
        'kds_enabled',
        'print_enabled',
    ];

    protected $casts = [
        'display_order' => 'integer',
        'is_active' => 'boolean',
        'kds_enabled' => 'boolean',
        'print_enabled' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'production_station_id');
    }
}
