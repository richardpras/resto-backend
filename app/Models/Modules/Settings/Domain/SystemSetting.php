<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'id',
        'enable_split_bill',
        'enable_multi_payment',
        'confirm_before_payment',
        'enable_qr_ordering',
        'enable_call_cashier',
        'require_customer_approval_for_adjustments',
        'qr_pending_confirmation_ttl_minutes',
        'enforce_stock_on_sale',
        'stock_enforcement_mode',
        'allow_negative_stock',
        'inventory_costing_method',
        'deferred_consumption_trigger',
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
        'qr_pending_confirmation_ttl_minutes' => 'integer',
        'enforce_stock_on_sale' => 'boolean',
        'allow_negative_stock' => 'boolean',
        'employee_self_service_enabled' => 'boolean',
    ];
}
