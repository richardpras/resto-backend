<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLoyaltyBalance extends Model
{
    public const CREATED_AT = null;

    protected $fillable = [
        'member_id',
        'current_points',
    ];

    protected function casts(): array
    {
        return [
            'current_points' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
