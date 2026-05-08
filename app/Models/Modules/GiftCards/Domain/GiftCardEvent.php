<?php

namespace App\Models\Modules\GiftCards\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardEvent extends Model
{
    protected $fillable = [
        'issuance_id',
        'outlet_id',
        'event_type',
        'event_idempotency_key',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(GiftCardIssuance::class, 'issuance_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
