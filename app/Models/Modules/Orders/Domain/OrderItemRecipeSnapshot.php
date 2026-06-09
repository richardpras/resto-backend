<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\Menu\Domain\RecipeVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemRecipeSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_item_id',
        'recipe_version_id',
        'menu_item_id',
        'version_number',
        'recipe_snapshot_json',
        'created_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'recipe_snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }
}
