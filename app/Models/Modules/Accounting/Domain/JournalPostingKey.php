<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;

class JournalPostingKey extends Model
{
    protected $fillable = [
        'scope',
        'idempotency_key',
        'request_hash',
        'journal_id',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
