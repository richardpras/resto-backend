<?php

namespace App\Models\Modules\Menu\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    public const TYPE_FOOD_COST = 'food_cost';

    public const TYPE_MARGIN_EROSION = 'margin_erosion';

    public const TYPE_STAR_TO_PLOWHORSE = 'star_to_plowhorse';

    public const TYPE_STAR_TO_DOG = 'star_to_dog';

    public const TYPE_DEAD_STOCK = 'dead_stock';

    public const TYPE_INVENTORY_VALUE_SPIKE = 'inventory_value_spike';

    public const TYPE_YIELD_LOSS = 'yield_loss';

    public const TYPE_MENU_REMOVAL = 'menu_removal';

    protected $fillable = [
        'outlet_id',
        'rule_name',
        'rule_type',
        'threshold_value',
        'severity',
        'notification_channels',
        'escalation_enabled',
        'is_active',
    ];

    protected $casts = [
        'threshold_value' => 'float',
        'notification_channels' => 'array',
        'escalation_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function alerts(): HasMany
    {
        return $this->hasMany(AutomationAlert::class);
    }
}
