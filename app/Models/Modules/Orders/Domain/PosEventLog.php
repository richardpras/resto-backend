<?php

namespace App\Models\Modules\Orders\Domain;

use Illuminate\Database\Eloquent\Model;

class PosEventLog extends Model
{
    protected $table = 'pos_event_logs';

    protected $fillable = [
        'outlet_id',
        'actor_user_id',
        'event_type',
        'entity_type',
        'entity_id',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}
