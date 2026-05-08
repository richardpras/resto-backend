<?php

namespace App\Models\Modules\GiftCards\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardRedemptionSettlement extends Model
{
    protected $fillable = [
        'issuance_id',
        'ledger_entry_id',
        'outlet_id',
        'idempotency_key',
        'settlement_reference',
        'payment_transaction_id',
        'redeemed_amount',
        'status',
        'redeemed_at',
        'settled_at',
        'meta',
    ];

    protected $casts = [
        'redeemed_amount' => 'decimal:2',
        'payment_transaction_id' => 'integer',
        'redeemed_at' => 'datetime',
        'settled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(GiftCardIssuance::class, 'issuance_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(GiftCardLedger::class, 'ledger_entry_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
