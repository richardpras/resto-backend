<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;

class BpjsConfig extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'effective_date',
        'kesehatan_employee_rate',
        'kesehatan_company_rate',
        'jht_employee_rate',
        'jht_company_rate',
        'jp_employee_rate',
        'jp_company_rate',
        'jkk_company_rate',
        'jkm_company_rate',
        'status',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'kesehatan_employee_rate' => 'float',
        'kesehatan_company_rate' => 'float',
        'jht_employee_rate' => 'float',
        'jht_company_rate' => 'float',
        'jp_employee_rate' => 'float',
        'jp_company_rate' => 'float',
        'jkk_company_rate' => 'float',
        'jkm_company_rate' => 'float',
    ];
}
