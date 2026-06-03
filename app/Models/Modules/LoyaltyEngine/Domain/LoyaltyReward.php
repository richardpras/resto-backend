<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyReward extends Model
{
    protected $table = 'loyalty_rewards';

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'points_cost',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'points_cost' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
