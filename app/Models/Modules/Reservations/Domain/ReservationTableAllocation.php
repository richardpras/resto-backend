<?php

namespace App\Models\Modules\Reservations\Domain;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationTableAllocation extends Model
{
    protected $fillable = [
        'reservation_id',
        'table_id',
        'allocated_at',
        'allocated_by_user_id',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_user_id');
    }
}
