<?php

namespace App\Models\Modules\Settings\Domain;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $table = 'bank_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'bank_name',
        'account_name',
        'account_number',
        'is_default',
        'chart_account_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'chart_account_id' => 'integer',
    ];

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'chart_account_id');
    }
}
