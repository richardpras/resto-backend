<?php

namespace App\Models\Modules\Loyalty\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointsLedger extends Model
{
    protected $fillable = [
        'loyalty_account_id',
        'outlet_id',
        'created_by_user_id',
        'idempotency_key',
        'transaction_type',
        'reference_type',
        'reference_id',
        'points_delta',
        'balance_before',
        'balance_after',
        'spend_amount',
        'visit_increment',
        'meta',
        'client_occurred_at',
        'applied_at',
        'stale_rejected_at',
    ];

    protected $casts = [
        'points_delta' => 'integer',
        'balance_before' => 'integer',
        'balance_after' => 'integer',
        'spend_amount' => 'decimal:2',
        'visit_increment' => 'integer',
        'meta' => 'array',
        'client_occurred_at' => 'datetime',
        'applied_at' => 'datetime',
        'stale_rejected_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
