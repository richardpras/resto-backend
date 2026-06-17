<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrGuestSession extends Model
{
    protected $fillable = [
        'session_token',
        'outlet_id',
        'table_id',
        'qr_public_id',
        'status',
        'expires_at',
        'last_seen_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Modules\Settings\Domain\Outlet::class, 'outlet_id');
    }

    public function qrOrderRequests(): HasMany
    {
        return $this->hasMany(QrOrderRequest::class, 'guest_session_id');
    }

    public function isActive(): bool
    {
        return (string) $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
