<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $fillable = [
        'payment_gateway_key',
        'webhook_url',
        'print_agent_url',
        'third_party_notes',
    ];
}
