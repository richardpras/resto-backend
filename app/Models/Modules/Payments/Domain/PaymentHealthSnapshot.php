<?php

namespace App\Models\Modules\Payments\Domain;

use Illuminate\Database\Eloquent\Model;

class PaymentHealthSnapshot extends Model
{
    protected $fillable = [
        'outlet_id',
        'provider',
        'snapshot_date',
        'health_status',
        'payment_success_rate',
        'webhook_success_rate',
        'stale_payments',
        'failed_webhooks',
        'average_processing_time_ms',
        'active_incidents',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'payment_success_rate' => 'float',
            'webhook_success_rate' => 'float',
        ];
    }
}
