<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;

class MerchantSetting extends Model
{
    protected $fillable = [
        'name',
        'business_type',
        'address',
        'phone',
        'email',
        'currency',
        'timezone',
        'language',
        'logo',
    ];
}
