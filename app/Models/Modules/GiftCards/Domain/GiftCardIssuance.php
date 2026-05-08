<?php

namespace App\Models\Modules\GiftCards\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCardIssuance extends Model
{
    protected $fillable = [
        'outlet_id',
        'issued_by_user_id',
        'instrument_type',
        'code',
        'issued_amount',
        'balance_amount',
        'currency',
        'status',
        'issued_at',
        'expires_at',
        'last_redeemed_at',
        'meta',
    ];

    protected $casts = [
        'issued_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_redeemed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(GiftCardLedger::class, 'issuance_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(GiftCardRedemptionSettlement::class, 'issuance_id');
    }
}
