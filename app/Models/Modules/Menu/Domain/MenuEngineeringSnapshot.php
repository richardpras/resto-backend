<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class MenuEngineeringSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'outlet_id',
        'menu_item_id',
        'quantity_sold',
        'popularity_percent',
        'contribution_margin',
        'margin_percent',
        'classification',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'quantity_sold' => 'float',
        'popularity_percent' => 'float',
        'contribution_margin' => 'float',
        'margin_percent' => 'float',
    ];
}
