<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTable extends Model
{
    protected $table = 'tables';

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'capacity',
        'zone',
        'status',
        'active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'active' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
