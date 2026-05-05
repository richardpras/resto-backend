<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
