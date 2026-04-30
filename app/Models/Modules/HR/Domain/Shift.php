<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\HR\Domain\Attendance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'start_time',
        'end_time',
        'late_tolerance_minutes',
        'overtime_after_minutes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
