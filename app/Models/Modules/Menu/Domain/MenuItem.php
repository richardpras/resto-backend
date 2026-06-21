<?php

namespace App\Models\Modules\Menu\Domain;

use App\Models\Modules\Production\Domain\ProductionStation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MenuItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'name',
        'category',
        'menu_category_id',
        'production_station_id',
        'emoji',
        'image_path',
        'image_path_fallback',
        'image_version',
        'image_width',
        'image_height',
        'price',
        'available',
    ];

    public function productionStation(): BelongsTo
    {
        return $this->belongsTo(ProductionStation::class, 'production_station_id');
    }

    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuRecipe::class);
    }

    public function outletMappings(): HasMany
    {
        return $this->hasMany(MenuItemOutlet::class);
    }

    public function costSetting(): HasOne
    {
        return $this->hasOne(MenuRecipeCostSetting::class);
    }

    public function recipeVersions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }
}
