<?php

namespace App\Models\Modules\Inventory\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyStocktakeSession extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'outlet_id',
        'business_date',
        'status',
        'opening_submitted_at',
        'closing_submitted_at',
        'posted_at',
        'notes',
        'created_by_user_id',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_submitted_at' => 'datetime',
            'closing_submitted_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DailyStocktakeLine::class, 'session_id');
    }
}
