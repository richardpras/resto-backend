<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class DashboardSnapshot extends Model
{
    protected $fillable = [
        'snapshot_date',
        'outlet_id',
        'total_revenue',
        'food_cost_percent',
        'average_margin_percent',
        'star_count',
        'puzzle_count',
        'plowhorse_count',
        'dog_count',
        'active_alerts',
        'critical_alerts',
        'optimization_opportunities',
        'forecast_revenue',
        'forecast_margin',
        'inventory_value',
        'health_score',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_revenue' => 'float',
        'food_cost_percent' => 'float',
        'average_margin_percent' => 'float',
        'star_count' => 'integer',
        'puzzle_count' => 'integer',
        'plowhorse_count' => 'integer',
        'dog_count' => 'integer',
        'active_alerts' => 'integer',
        'critical_alerts' => 'integer',
        'optimization_opportunities' => 'integer',
        'forecast_revenue' => 'float',
        'forecast_margin' => 'float',
        'inventory_value' => 'float',
        'health_score' => 'float',
    ];
}
