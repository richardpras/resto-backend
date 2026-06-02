<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTable extends Model
{
    protected $table = 'tables';

    protected $fillable = [
        'outlet_id',
        'code',
        'qr_public_id',
        'name',
        'capacity',
        'zone',
        'status',
        'active',
        'qr_enabled',
        'qr_version',
        'qr_last_rotated_at',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'active' => 'boolean',
        'qr_enabled' => 'boolean',
        'qr_version' => 'integer',
        'qr_last_rotated_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
