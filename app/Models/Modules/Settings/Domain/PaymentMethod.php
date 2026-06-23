<?php

namespace App\Models\Modules\Settings\Domain;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'type',
        'integration',
        'fee',
        'status',
        'chart_account_id',
    ];

    protected $casts = [
        'fee' => 'decimal:4',
        'chart_account_id' => 'integer',
    ];

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'chart_account_id');
    }
}
