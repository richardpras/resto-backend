<?php

namespace App\Models\Modules\Loyalty\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRewardRedemption extends Model
{
    protected $table = 'loyalty_account_reward_redemptions';

    protected $fillable = [
        'loyalty_account_id',
        'outlet_id',
        'created_by_user_id',
        'ledger_entry_id',
        'idempotency_key',
        'reward_code',
        'points_cost',
        'status',
        'meta',
        'redeemed_at',
        'stale_rejected_at',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'meta' => 'array',
        'redeemed_at' => 'datetime',
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

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPointsLedger::class, 'ledger_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
