<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HardwareBridgeDevice extends Model
{
    protected $table = 'hardware_bridge_devices';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'device_key',
        'display_label',
        'capabilities',
        'metadata',
        'status',
        'last_seen_at',
        'revoked_at',
        'disabled_at',
        'reconnect_count',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'disabled_at' => 'datetime',
            'reconnect_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Outlet, HardwareBridgeDevice> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return HasMany<HardwareDeviceSession> */
    public function sessions(): HasMany
    {
        return $this->hasMany(HardwareDeviceSession::class);
    }

    public function isUsable(): bool
    {
        return (string) $this->status === 'active'
            && $this->revoked_at === null
            && $this->disabled_at === null;
    }
}
