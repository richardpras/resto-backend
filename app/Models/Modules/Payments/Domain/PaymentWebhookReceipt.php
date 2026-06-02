<?php

namespace App\Models\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhookReceipt extends Model
{
    protected $fillable = [
        'provider',
        'event_idempotency_key',
        'external_reference',
        'incoming_status',
        'payload_hash',
        'payload',
        'headers',
        'signed_payload',
        'process_attempts',
        'processed_at',
        'next_retry_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];
}
