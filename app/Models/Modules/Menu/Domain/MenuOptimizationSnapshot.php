<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;

class MenuOptimizationSnapshot extends Model
{
    public const TYPE_ENGINEERING = 'engineering';

    public const TYPE_PRICE = 'price';

    public const TYPE_BUNDLE = 'bundle';

    public const TYPE_INGREDIENT = 'ingredient';

    public const TYPE_YIELD = 'yield';

    protected $fillable = [
        'snapshot_date',
        'outlet_id',
        'menu_item_id',
        'recommendation_type',
        'recommendation_json',
        'projected_margin_percent',
        'projected_profit_increase',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'recommendation_json' => 'array',
        'projected_margin_percent' => 'float',
        'projected_profit_increase' => 'float',
    ];
}
