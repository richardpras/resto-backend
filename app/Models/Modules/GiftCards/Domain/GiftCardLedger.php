<?php

namespace App\Models\Modules\GiftCards\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardLedger extends Model
{
    protected $fillable = [
        'issuance_id',
        'outlet_id',
        'created_by_user_id',
        'transaction_type',
        'idempotency_key',
        'reference_type',
        'reference_id',
        'amount_delta',
        'balance_before',
        'balance_after',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'amount_delta' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta' => 'array',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
