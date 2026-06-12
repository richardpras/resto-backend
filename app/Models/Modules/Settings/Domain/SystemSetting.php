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
        'enable_call_cashier',
        'require_customer_approval_for_adjustments',
        'enforce_stock_on_sale',
        'stock_enforcement_mode',
        'allow_negative_stock',
        'shift_close_open_bill_policy',
        'customer_app_url',
        'employee_self_service_enabled',
    ];

    protected $casts = [
        'enable_split_bill' => 'boolean',
        'enable_multi_payment' => 'boolean',
        'confirm_before_payment' => 'boolean',
        'enable_qr_ordering' => 'boolean',
        'enable_call_cashier' => 'boolean',
        'require_customer_approval_for_adjustments' => 'boolean',
        'enforce_stock_on_sale' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'employee_self_service_enabled' => 'boolean',
    ];
}
