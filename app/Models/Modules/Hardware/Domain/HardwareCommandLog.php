<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareCommandLog extends Model
{
    protected $table = 'hardware_command_logs';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'hardware_bridge_device_id',
        'hardware_device_session_id',
        'command_type',
        'status',
        'idempotency_key',
        'payload',
        'ack_payload',
        'nack_payload',
        'retry_count',
        'max_retries',
        'next_retry_at',
        'acked_at',
        'nacked_at',
        'dead_lettered_at',
        'last_error_code',
        'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'ack_payload' => 'array',
            'nack_payload' => 'array',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'next_retry_at' => 'datetime',
            'acked_at' => 'datetime',
            'nacked_at' => 'datetime',
            'dead_lettered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, HardwareCommandLog> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
