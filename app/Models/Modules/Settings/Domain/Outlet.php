<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Outlet extends Model
{
    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'manager',
        'status',
        'logo',
        'logo_path',
        'logo_path_fallback',
        'logo_thermal_path',
        'logo_version',
        'invoice_prefix',
        'order_prefix',
    ];

    public function receiptSetting(): HasOne
    {
        return $this->hasOne(OutletReceiptSetting::class, 'outlet_id');
    }

    public function paymentMethodConfigs(): HasMany
    {
        return $this->hasMany(OutletPaymentMethodConfig::class, 'outlet_id');
    }
}
