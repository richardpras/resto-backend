<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\Menu\Domain\MenuItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrOrderRequestItem extends Model
{
    protected $fillable = [
        'qr_order_request_id',
        'menu_item_id',
        'qty',
        'notes',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(QrOrderRequest::class, 'qr_order_request_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
