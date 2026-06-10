<?php

namespace App\Models\Modules\System\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BugReport extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_TRIAGED = 'triaged';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_FIXED = 'fixed';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_WONT_FIX = 'wont_fix';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_TRIAGED,
        self::STATUS_INVESTIGATING,
        self::STATUS_FIXED,
        self::STATUS_CLOSED,
        self::STATUS_WONT_FIX,
    ];

    /** @var list<string> */
    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    protected $fillable = [
        'outlet_id',
        'reporter_user_id',
        'title',
        'message',
        'severity',
        'status',
        'current_route',
        'browser',
        'user_agent',
        'viewport',
        'app_version',
        'diagnostics_json',
        'assigned_to_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'diagnostics_json' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return HasMany<BugReportAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(BugReportAttachment::class);
    }

    /** @return HasMany<BugReportComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(BugReportComment::class)->orderBy('created_at');
    }
}
