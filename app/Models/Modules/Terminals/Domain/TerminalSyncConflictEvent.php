<?php

namespace App\Models\Modules\Terminals\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $outlet_id
 * @property int|null $terminal_device_id
 * @property int $terminal_sync_operation_id
 * @property string $conflict_type
 * @property string|null $recommendation
 * @property array<string,mixed>|null $details
 */
class TerminalSyncConflictEvent extends Model
{
    public $timestamps = false;

    protected $table = 'terminal_sync_conflict_events';

    /** @var list<string> */
    protected $fillable = [
        'outlet_id',
        'terminal_device_id',
        'terminal_sync_operation_id',
        'conflict_type',
        'recommendation',
        'details',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /** @return BelongsTo<TerminalSyncOperation, TerminalSyncConflictEvent> */
    public function syncOperation(): BelongsTo
    {
        return $this->belongsTo(TerminalSyncOperation::class, 'terminal_sync_operation_id');
    }
}
