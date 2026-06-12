<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;

class InventoryIncident extends Model
{
    public const TYPE_INSUFFICIENT_ON_POSTING = 'inventory_insufficient_on_posting';

    public const TYPE_INSUFFICIENT_ON_SALE = 'inventory_insufficient_on_sale';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'outlet_id',
        'order_id',
        'ingredient_id',
        'incident_type',
        'severity',
        'title',
        'description',
        'expected_quantity',
        'available_quantity',
        'variance',
        'status',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'float',
            'available_quantity' => 'float',
            'variance' => 'float',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
