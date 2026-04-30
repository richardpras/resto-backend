<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAuditLog extends Model
{
    protected $fillable = [
        'attendance_id',
        'actor_user_id',
        'action',
        'before_json',
        'after_json',
        'reason',
        'source_type',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
