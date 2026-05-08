<?php

namespace App\Models\Modules\Hardware\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareDeviceEvent extends Model
{
    protected $table = 'hardware_device_events';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'hardware_bridge_device_id',
        'hardware_device_session_id',
        'event_type',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, HardwareDeviceEvent> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
