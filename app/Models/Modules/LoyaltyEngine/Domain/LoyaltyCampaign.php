<?php

namespace App\Models\Modules\LoyaltyEngine\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const TYPE_AUDIENCE = 'audience';

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'description',
        'segment_id',
        'campaign_type',
        'scheduled_at',
        'status',
        'activated_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'segment_id' => 'integer',
            'scheduled_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(MemberSegment::class, 'segment_id');
    }

    public function audienceSnapshots(): HasMany
    {
        return $this->hasMany(LoyaltyCampaignAudience::class, 'campaign_id');
    }
}
