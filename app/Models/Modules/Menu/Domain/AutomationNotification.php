<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationNotification extends Model
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WEBHOOK = 'webhook';

    protected $fillable = [
        'outlet_id',
        'automation_alert_id',
        'channel',
        'status',
        'payload_json',
        'sent_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'sent_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(AutomationAlert::class, 'automation_alert_id');
    }
}
