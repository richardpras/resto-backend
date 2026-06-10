<?php

namespace App\Models\Modules\Notifications\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_SUCCESS = 'success';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const MODULE_ACCOUNTING = 'accounting';

    public const MODULE_PAYMENTS = 'payments';

    public const MODULE_MONITORING = 'monitoring';

    public const MODULE_INVENTORY = 'inventory';

    public const MODULE_PROCUREMENT = 'procurement';

    public const MODULE_PAYROLL = 'payroll';

    public const MODULE_HR = 'hr';

    public const MODULE_CRM = 'crm';

    public const MODULE_SYSTEM = 'system';

    public const MODULE_MENU_INTELLIGENCE = 'menu_intelligence';

    protected $fillable = [
        'outlet_id',
        'user_id',
        'severity',
        'source_module',
        'source_type',
        'source_id',
        'title',
        'message',
        'action_url',
        'read_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
