<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeVersion extends Model
{
    protected $fillable = [
        'menu_item_id',
        'version_number',
        'name',
        'notes',
        'status',
        'activated_at',
        'created_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeVersionItem::class);
    }
}
