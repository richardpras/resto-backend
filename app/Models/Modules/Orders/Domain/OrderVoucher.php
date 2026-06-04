<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderVoucher extends Model
{
    protected $fillable = [
        'order_id',
        'member_voucher_id',
        'voucher_id',
        'voucher_code',
        'discount_type',
        'discount_value',
        'discount_amount',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'member_voucher_id' => 'integer',
            'voucher_id' => 'integer',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(LoyaltyVoucher::class, 'voucher_id');
    }

    public function memberVoucher(): BelongsTo
    {
        return $this->belongsTo(MemberVoucher::class, 'member_voucher_id');
    }
}
