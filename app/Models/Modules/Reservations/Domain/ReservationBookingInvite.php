<?php

namespace App\Models\Modules\Reservations\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationBookingInvite extends Model
{
    protected $fillable = [
        'outlet_id',
        'token',
        'expires_at',
        'max_uses',
        'used_count',
        'created_by_user_id',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'max_uses' => 'integer',
        'used_count' => 'integer',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExhausted(): bool
    {
        return (int) $this->used_count >= (int) $this->max_uses;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked() && ! $this->isExhausted();
    }
}
