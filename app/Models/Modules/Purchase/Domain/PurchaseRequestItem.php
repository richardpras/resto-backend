<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Inventory\Domain\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id',
        'ingredient_id',
        'requested_qty',
        'unit',
    ];

    protected $casts = [
        'requested_qty' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
