<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwarePairingCode extends Model
{
    protected $fillable = [
        'outlet_id',
        'code_hash',
        'created_by_user_id',
        'display_label',
        'expires_at',
        'consumed_at',
        'consumed_device_id',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function consumedDevice(): BelongsTo
    {
        return $this->belongsTo(HardwareBridgeDevice::class, 'consumed_device_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
