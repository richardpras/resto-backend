<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrOrderRequest extends Model
{
    protected $fillable = [
        'outlet_id',
        'table_id',
        'request_code',
        'customer_name',
        'status',
        'expires_at',
        'confirmed_at',
        'rejected_at',
        'confirmed_by_user_id',
        'rejected_by_user_id',
        'rejection_reason',
        'order_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QrOrderRequestItem::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }
}
