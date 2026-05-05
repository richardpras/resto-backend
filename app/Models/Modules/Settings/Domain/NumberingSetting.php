<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class NumberingSetting extends Model
{
    protected $fillable = [
        'invoice_format',
        'order_format',
    ];
}
