<?php

namespace App\Models\Modules\UserManagement\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserManagementAuditLog extends Model
{
    public const ENTITY_USER = 'user';

    public const ENTITY_ROLE = 'role';

    public const ENTITY_PERMISSION = 'permission';

    public const ACTION_USER_CREATED = 'user.created';

    public const ACTION_ROLE_PERMISSION_CHANGED = 'role_permission_changed';

    public const ACTION_USER_PIN_SET = 'user.pin_set';

    public const ACTION_USER_PIN_CLEARED = 'user.pin_cleared';

    public const ACTION_ROLE_CREATED = 'role.created';

    public const ACTION_PERMISSION_CREATED = 'permission.created';

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'target_user_id',
        'entity_type',
        'entity_id',
        'action',
        'before_json',
        'after_json',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, UserManagementAuditLog> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<User, UserManagementAuditLog> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
