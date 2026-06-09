<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class AutomationEscalationRule extends Model
{
    protected $table = 'automation_escalation_rules';

    protected $fillable = [
        'outlet_id',
        'severity',
        'day_offset',
        'notify_role',
        'is_active',
    ];

    protected $casts = [
        'day_offset' => 'integer',
        'is_active' => 'boolean',
    ];
}
