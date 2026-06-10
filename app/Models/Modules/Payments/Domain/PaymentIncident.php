<?php

namespace App\Models\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;

class PaymentIncident extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const TYPE_PROVIDER_CRITICAL = 'provider_critical';

    public const TYPE_WEBHOOK_SPIKE = 'webhook_failure_spike';

    public const TYPE_STALE_SPIKE = 'stale_payment_spike';

    public const TYPE_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    protected $fillable = [
        'outlet_id',
        'provider',
        'incident_type',
        'severity',
        'title',
        'description',
        'opened_at',
        'resolved_at',
        'duration_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
