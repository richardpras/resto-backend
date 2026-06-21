<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuCategory extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'name_en',
        'name_id',
        'description',
        'description_en',
        'description_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_category_id');
    }

    public function printerMappings(): HasMany
    {
        return $this->hasMany(MenuCategoryPrinterMapping::class, 'menu_category_id');
    }
}
