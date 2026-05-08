<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterDeviceProfile extends Model
{
    protected $table = 'printer_device_profiles';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'hardware_bridge_device_id',
        'printer_code',
        'name',
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
        'status',
        'is_enabled',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'reconnect_metadata' => 'array',
            'signal_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Outlet, PrinterDeviceProfile> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
