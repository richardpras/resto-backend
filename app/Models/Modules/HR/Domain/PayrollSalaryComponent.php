<?php

namespace App\Models\Modules\HR\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollSalaryComponent extends Model
{
    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
