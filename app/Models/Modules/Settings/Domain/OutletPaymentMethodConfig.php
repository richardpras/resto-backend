<?php

namespace App\Models\Modules\Settings\Domain;

use App\Models\Modules\Accounting\Domain\Account;
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
        'chart_account_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_default' => 'boolean',
        'display_order' => 'integer',
        'settings' => 'array',
        'chart_account_id' => 'integer',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'chart_account_id');
    }
}
