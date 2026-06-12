<?php

namespace App\Models\Modules\Inventory\Domain;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryConsumptionQueue extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVIEW_REQUIRED = 'review_required';

    protected $table = 'inventory_consumption_queue';

    protected $fillable = [
        'outlet_id',
        'order_id',
        'status',
        'payload',
        'failure_reason',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
