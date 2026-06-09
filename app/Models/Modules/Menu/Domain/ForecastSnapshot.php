<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class ForecastSnapshot extends Model
{
    public const TYPE_DAILY_DEMAND = 'daily_demand';

    public const TYPE_WEEKLY_DEMAND = 'weekly_demand';

    public const TYPE_MONTHLY_DEMAND = 'monthly_demand';

    public const TYPE_DAILY_REVENUE = 'daily_revenue';

    public const TYPE_WEEKLY_REVENUE = 'weekly_revenue';

    public const TYPE_MONTHLY_REVENUE = 'monthly_revenue';

    public const TYPE_INGREDIENT_CONSUMPTION = 'ingredient_consumption';

    public const TYPE_FOOD_COST = 'food_cost';

    public const TYPE_PRODUCTION_REQUIREMENT = 'production_requirement';

    public const TYPE_STOCK_RISK = 'stock_risk';

    protected $fillable = [
        'snapshot_date',
        'forecast_date',
        'outlet_id',
        'menu_item_id',
        'inventory_item_id',
        'forecast_type',
        'predicted_quantity',
        'predicted_revenue',
        'predicted_margin',
        'confidence_score',
        'metadata_json',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'forecast_date' => 'date',
        'predicted_quantity' => 'float',
        'predicted_revenue' => 'float',
        'predicted_margin' => 'float',
        'confidence_score' => 'float',
        'metadata_json' => 'array',
    ];
}
