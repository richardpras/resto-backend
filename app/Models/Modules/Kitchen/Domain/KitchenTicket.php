<?php

namespace App\Models\Modules\Kitchen\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenTicket extends Model
{
    protected $fillable = [
        'outlet_id',
        'order_id',
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

    public function items(): HasMany
    {
        return $this->hasMany(KitchenTicketItem::class);
    }
}
