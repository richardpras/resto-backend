<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'enable_split_bill',
        'enable_multi_payment',
        'confirm_before_payment',
        'enable_qr_ordering',
    ];

    protected $casts = [
        'enable_split_bill' => 'boolean',
        'enable_multi_payment' => 'boolean',
        'confirm_before_payment' => 'boolean',
        'enable_qr_ordering' => 'boolean',
    ];
}
