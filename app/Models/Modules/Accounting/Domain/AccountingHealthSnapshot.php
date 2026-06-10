<?php

namespace App\Models\Modules\Accounting\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingHealthSnapshot extends Model
{
    public const SEVERITY_HEALTHY = 'healthy';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'outlet_id',
        'snapshot_date',
        'posting_failures',
        'gift_card_variance',
        'inventory_variance',
        'payroll_variance',
        'procurement_variance',
        'severity',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'gift_card_variance' => 'float',
            'inventory_variance' => 'float',
            'payroll_variance' => 'float',
            'procurement_variance' => 'float',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
