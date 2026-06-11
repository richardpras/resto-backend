<?php

namespace App\Models\Modules\Kitchen\Domain;

use App\Models\Modules\Orders\Domain\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenTicketItem extends Model
{
    protected $fillable = [
        'kitchen_ticket_id',
        'order_item_id',
        'production_station_id',
        'station_code',
        'station_name',
        'item_name_snapshot',
        'qty',
        'notes',
        'status',
    ];

    public function productionStation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Modules\Production\Domain\ProductionStation::class, 'production_station_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(KitchenTicket::class, 'kitchen_ticket_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
