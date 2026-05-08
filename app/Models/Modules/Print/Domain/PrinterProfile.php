<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrinterProfile extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'code',
        'name',
        'station',
        'connection_type',
        'device_identifier',
        'ip_address',
        'mac_address',
        'bluetooth_name',
        'bluetooth_address',
        'pairing_state',
        'last_connected_at',
        'reconnect_metadata',
        'signal_metadata',
        'endpoint',
        'is_active',
        'health_status',
        'queue_state',
        'last_heartbeat_at',
        'last_error_at',
        'last_error_message',
        'retry_policy',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'retry_policy' => 'array',
        'meta' => 'array',
        'last_heartbeat_at' => 'datetime',
        'last_error_at' => 'datetime',
        'last_connected_at' => 'datetime',
        'reconnect_metadata' => 'array',
        'signal_metadata' => 'array',
    ];

    public function routes(): HasMany
    {
        return $this->hasMany(PrinterRoute::class, 'printer_profile_id');
    }
}
