<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPostingMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'module',
        'rule_key',
        'chart_account_id',
    ];

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'chart_account_id');
    }
}
