<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;

class AccountingSetting extends Model
{
    public const MODE_REALTIME = 'realtime';

    public const MODE_SHIFT_CLOSE = 'shift_close';

    /** @var list<string> */
    public const REVENUE_MODES = [self::MODE_REALTIME, self::MODE_SHIFT_CLOSE];

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'revenue_posting_mode',
    ];
}
