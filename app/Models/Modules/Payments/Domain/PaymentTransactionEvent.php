<?php

namespace App\Models\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransactionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_transaction_id',
        'event_type',
        'event_idempotency_key',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
