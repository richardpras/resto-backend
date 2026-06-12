<?php

namespace App\Models\Modules\ShiftClose\Domain;

use Illuminate\Database\Eloquent\Model;

class ShiftCloseRun extends Model
{
    public const STATUS_RUNNING = 'running';

    /** @deprecated Use STATUS_RUNNING */
    public const STATUS_STARTED = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_WARNINGS = 'completed_with_warnings';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'shift_date',
        'pos_session_id',
        'run_by_user_id',
        'created_by_user_id',
        'status',
        'severity',
        'ready',
        'preflight_snapshot',
        'result_snapshot',
        'sales_amount',
        'cash_sales',
        'non_cash_sales',
        'opening_cash',
        'cash_refunds',
        'cash_expenses',
        'cash_in',
        'cash_out',
        'cash_expected',
        'cash_actual',
        'cash_variance',
        'expected_cash',
        'actual_cash',
        'inventory_variance',
        'open_bill_count',
        'open_pos_session_count',
        'pending_qr_count',
        'under_review_qr_count',
        'linked_unpaid_qr_bill_count',
        'pending_consumption_count',
        'failed_accounting_posting_count',
        'metadata',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'ready' => 'boolean',
            'preflight_snapshot' => 'array',
            'result_snapshot' => 'array',
            'metadata' => 'array',
            'sales_amount' => 'float',
            'cash_sales' => 'float',
            'non_cash_sales' => 'float',
            'opening_cash' => 'float',
            'cash_refunds' => 'float',
            'cash_expenses' => 'float',
            'cash_in' => 'float',
            'cash_out' => 'float',
            'cash_expected' => 'float',
            'cash_actual' => 'float',
            'cash_variance' => 'float',
            'expected_cash' => 'float',
            'actual_cash' => 'float',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
