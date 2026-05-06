<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
