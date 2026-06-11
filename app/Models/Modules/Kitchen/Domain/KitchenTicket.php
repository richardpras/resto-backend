<?php

namespace App\Models\Modules\Kitchen\Domain;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenTicket extends Model
{
    protected $fillable = [
        'outlet_id',
        'order_id',
        'production_station_id',
        'station_code',
        'station_name',
        'ticket_no',
        'status',
        'queued_at',
        'started_at',
        'ready_at',
        'served_at',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function productionStation(): BelongsTo
    {
        return $this->belongsTo(ProductionStation::class, 'production_station_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }
}
