<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuRecipeCostSetting extends Model
{
    protected $fillable = [
        'menu_item_id',
        'yield_percent',
        'waste_percent',
        'is_active',
    ];

    protected $casts = [
        'yield_percent' => 'float',
        'waste_percent' => 'float',
        'is_active' => 'boolean',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
