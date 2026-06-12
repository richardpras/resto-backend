<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;

class PosIdempotencyKey extends Model
{
    protected $table = 'pos_idempotency_keys';

    protected $fillable = [
        'scope',
        'idempotency_key',
        'request_hash',
        'response_payload',
        'processed_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
