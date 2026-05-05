<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletReceiptSetting extends Model
{
    protected $fillable = [
        'outlet_id',
        'receipt_header',
        'receipt_footer',
        'show_logo',
        'show_tax_breakdown',
    ];

    protected $casts = [
        'show_logo' => 'boolean',
        'show_tax_breakdown' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id', 'id');
    }
}
