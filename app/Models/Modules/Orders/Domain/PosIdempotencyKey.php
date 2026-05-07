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
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
