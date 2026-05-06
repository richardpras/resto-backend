<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
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
        'invoice_prefix',
        'order_prefix',
    ];

    public function receiptSetting(): HasOne
    {
        return $this->hasOne(OutletReceiptSetting::class, 'outlet_id');
    }
}
