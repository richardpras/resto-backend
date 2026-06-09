<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationAlert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'outlet_id',
        'automation_rule_id',
        'alert_type',
        'severity',
        'title',
        'description',
        'payload_json',
        'status',
        'triggered_at',
        'resolved_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AutomationNotification::class);
    }
}
