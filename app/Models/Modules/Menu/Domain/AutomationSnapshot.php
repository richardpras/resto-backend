<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class AutomationSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'outlet_id',
        'alerts_generated',
        'critical_alerts',
        'warnings',
        'recommendations_generated',
        'resolved_alerts',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'alerts_generated' => 'integer',
        'critical_alerts' => 'integer',
        'warnings' => 'integer',
        'recommendations_generated' => 'integer',
        'resolved_alerts' => 'integer',
    ];
}
