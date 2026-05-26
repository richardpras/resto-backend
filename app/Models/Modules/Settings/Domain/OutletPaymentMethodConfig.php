<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletPaymentMethodConfig extends Model
{
    protected $fillable = [
        'outlet_id',
        'payment_method_code',
        'type',
        'provider',
        'enabled',
        'display_order',
        'is_default',
        'settings',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
        'display_order' => 'integer',
        'settings' => 'array',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
