<?php

namespace App\Models\Modules\Reservations\Domain;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $fillable = [
        'outlet_id',
        'table_id',
        'reservation_code',
        'customer_name',
        'customer_phone',
        'party_size',
        'reservation_at',
        'checked_in_at',
        'seated_at',
        'completed_at',
        'cancelled_at',
        'no_show_at',
        'status',
    ];

    protected $casts = [
        'party_size' => 'integer',
        'reservation_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'seated_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'no_show_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
