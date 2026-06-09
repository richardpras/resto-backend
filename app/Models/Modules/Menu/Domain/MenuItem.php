<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MenuItem extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'name',
        'category',
        'emoji',
        'price',
        'available',
    ];

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
