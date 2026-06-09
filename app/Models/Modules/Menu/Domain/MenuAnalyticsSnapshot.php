<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class MenuAnalyticsSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'outlet_id',
        'average_food_cost_percent',
        'average_margin_percent',
        'inventory_value',
        'daily_cogs',
        'production_efficiency_percent',
        'total_sales',
        'total_orders',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'average_food_cost_percent' => 'float',
        'average_margin_percent' => 'float',
        'inventory_value' => 'float',
        'daily_cogs' => 'float',
        'production_efficiency_percent' => 'float',
        'total_sales' => 'float',
        'total_orders' => 'integer',
    ];
}
