<?php

namespace App\Models\Modules\Hardware\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareDeviceCredential extends Model
{
    protected $fillable = [
        'hardware_bridge_device_id',
        'token_hash',
        'refresh_token_hash',
        'expires_at',
        'refresh_expires_at',
        'last_rotated_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(HardwareBridgeDevice::class, 'hardware_bridge_device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isAccessExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRefreshExpired(): bool
    {
        return $this->refresh_expires_at->isPast();
    }
}
