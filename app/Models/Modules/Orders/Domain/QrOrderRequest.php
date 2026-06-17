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
        'guest_session_id',
        'request_code',
        'customer_name',
        'status',
        'decision_mode',
        'expires_at',
        'confirmed_at',
        'rejected_at',
        'confirmed_by_user_id',
        'rejected_by_user_id',
        'rejection_reason',
        'order_id',
        'cashier_called_at',
        'cashier_call_count',
        'last_cashier_call_reason',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_draft',
        'adjustment_log',
        'customer_approval_status',
        'customer_served_at',
        'opened_in_pos_at',
        'opened_in_pos_by_user_id',
        'original_items_snapshot',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cashier_called_at' => 'datetime',
        'cashier_call_count' => 'integer',
        'reviewed_at' => 'datetime',
        'review_draft' => 'array',
        'adjustment_log' => 'array',
        'customer_served_at' => 'datetime',
        'opened_in_pos_at' => 'datetime',
        'original_items_snapshot' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QrOrderRequestItem::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function guestSession(): BelongsTo
    {
        return $this->belongsTo(QrGuestSession::class, 'guest_session_id');
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
