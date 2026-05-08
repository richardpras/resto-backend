<?php

namespace App\Models\Modules\Terminals\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int|null $terminal_device_id
 * @property string $operation_type
 * @property string $fingerprint
 * @property array<string,mixed>|null $payload
 * @property string $status
 * @property array<string,mixed>|null $outcome_summary
 * @property string|null $failure_message
 * @property string|null $conflict_type
 * @property array<string,mixed>|null $conflict_detail
 * @property string|null $duplicate_recommendation
 * @property Carbon|null $client_occurred_at
 * @property Carbon|null $server_applied_at
 * @property int $duplicate_replay_hits
 */
class TerminalSyncOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED_STALE = 'rejected_stale';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FAILED = 'failed';

    protected $table = 'terminal_sync_operations';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'terminal_device_id',
        'operation_type',
        'fingerprint',
        'payload',
        'status',
        'outcome_summary',
        'failure_message',
        'conflict_type',
        'conflict_detail',
        'duplicate_recommendation',
        'client_occurred_at',
        'server_applied_at',
        'duplicate_replay_hits',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'outcome_summary' => 'array',
            'conflict_detail' => 'array',
            'client_occurred_at' => 'datetime',
            'server_applied_at' => 'datetime',
            'duplicate_replay_hits' => 'integer',
        ];
    }

    /** @return BelongsTo<TerminalDevice, TerminalSyncOperation> */
    public function terminalDevice(): BelongsTo
    {
        return $this->belongsTo(TerminalDevice::class, 'terminal_device_id');
    }
}
