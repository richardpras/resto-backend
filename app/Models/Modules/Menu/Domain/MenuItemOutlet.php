<?php

namespace App\Models\Modules\Menu\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemOutlet extends Model
{
    protected $fillable = [
        'menu_item_id',
        'outlet_id',
        'is_active',
        'price_override',
        'name_override',
        'receipt_name',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_override' => 'float',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
