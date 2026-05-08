<?php

namespace App\Models\Modules\Terminals\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property string $device_key
 * @property string|null $display_label
 * @property array<string,mixed>|null $capabilities
 * @property array<string,mixed>|null $session_metadata
 * @property string $status
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 * @property int $reconnect_count
 */
class TerminalDevice extends Model
{
    protected $table = 'terminal_devices';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'device_key',
        'display_label',
        'capabilities',
        'session_metadata',
        'status',
        'revoked_at',
        'last_seen_at',
        'reconnect_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'session_metadata' => 'array',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reconnect_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Outlet, TerminalDevice> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isUsable(): bool
    {
        if ((string) $this->status !== 'active') {
            return false;
        }

        return $this->revoked_at === null;
    }
}
