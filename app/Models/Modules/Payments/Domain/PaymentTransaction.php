<?php

namespace App\Models\Modules\Payments\Domain;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'order_split_id',
        'outlet_id',
        'provider',
        'external_reference',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'reconciliation_attempts',
        'payment_method',
        'checkout_url',
        'qr_string',
        'deeplink_url',
        'va_number',
        'expiry_time',
        'payload_snapshot',
        'provider_metadata_snapshot',
        'paid_at',
        'expired_at',
        'last_reconciled_at',
        'async_retry_after',
        'last_async_error',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload_snapshot' => 'array',
        'provider_metadata_snapshot' => 'array',
        'reconciliation_attempts' => 'integer',
        'expiry_time' => 'datetime',
        'paid_at' => 'datetime',
        'expired_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'async_retry_after' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function split(): BelongsTo
    {
        return $this->belongsTo(OrderSplit::class, 'order_split_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentTransactionEvent::class);
    }
}
