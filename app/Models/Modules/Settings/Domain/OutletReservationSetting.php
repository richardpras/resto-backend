<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletReservationSetting extends Model
{
    protected $fillable = [
        'outlet_id',
        'public_enabled',
        'public_slug',
        'deposit_mode',
        'deposit_percent',
        'deposit_flat_amount',
        'preorder_required',
        'deposit_instructions',
        'deposit_review_timeout_hours',
        'invite_link_expiry_hours',
    ];

    protected $casts = [
        'public_enabled' => 'boolean',
        'deposit_percent' => 'decimal:2',
        'deposit_flat_amount' => 'decimal:2',
        'preorder_required' => 'boolean',
        'deposit_review_timeout_hours' => 'integer',
        'invite_link_expiry_hours' => 'integer',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
