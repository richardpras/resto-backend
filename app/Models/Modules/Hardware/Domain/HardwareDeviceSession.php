<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareDeviceSession extends Model
{
    protected $table = 'hardware_device_sessions';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'hardware_bridge_device_id',
        'session_token',
        'status',
        'metadata',
        'opened_at',
        'closed_at',
        'last_heartbeat_at',
        'reconnect_count',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'reconnect_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Outlet, HardwareDeviceSession> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<HardwareBridgeDevice, HardwareDeviceSession> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(HardwareBridgeDevice::class, 'hardware_bridge_device_id');
    }
}
