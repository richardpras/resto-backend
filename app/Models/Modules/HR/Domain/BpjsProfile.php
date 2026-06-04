<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsProfile extends Model
{
    protected $fillable = [
        'employee_id',
        'bpjs_kesehatan_no',
        'bpjs_tk_no',
        'bpjs_kesehatan_enabled',
        'bpjs_tk_enabled',
        'bpjs_salary_base',
        'kesehatan_employee_rate_override',
        'kesehatan_company_rate_override',
        'jht_employee_rate_override',
        'jht_company_rate_override',
        'jp_employee_rate_override',
        'jp_company_rate_override',
        'jkk_company_rate_override',
        'jkm_company_rate_override',
    ];

    protected $casts = [
        'bpjs_kesehatan_enabled' => 'boolean',
        'bpjs_tk_enabled' => 'boolean',
        'bpjs_salary_base' => 'float',
        'kesehatan_employee_rate_override' => 'float',
        'kesehatan_company_rate_override' => 'float',
        'jht_employee_rate_override' => 'float',
        'jht_company_rate_override' => 'float',
        'jp_employee_rate_override' => 'float',
        'jp_company_rate_override' => 'float',
        'jkk_company_rate_override' => 'float',
        'jkm_company_rate_override' => 'float',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
