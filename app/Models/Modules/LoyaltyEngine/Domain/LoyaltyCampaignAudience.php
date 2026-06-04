<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyCampaignAudience extends Model
{
    protected $fillable = [
        'campaign_id',
        'member_id',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'member_id' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCampaign::class, 'campaign_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
