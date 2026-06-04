<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\BpjsConfig;
use Illuminate\Support\Collection;

class BpjsConfigService
{
    /**
     * @return Collection<int, BpjsConfig>
     */
    public function list(): Collection
    {
        return BpjsConfig::query()->orderByDesc('effective_date')->get();
    }

    public function create(array $payload): BpjsConfig
    {
        return BpjsConfig::query()->create([
            'effective_date' => $payload['effectiveDate'],
            'kesehatan_employee_rate' => (float) ($payload['kesehatanEmployeeRate'] ?? 1),
            'kesehatan_company_rate' => (float) ($payload['kesehatanCompanyRate'] ?? 4),
            'jht_employee_rate' => (float) ($payload['jhtEmployeeRate'] ?? 2),
            'jht_company_rate' => (float) ($payload['jhtCompanyRate'] ?? 3.7),
            'jp_employee_rate' => (float) ($payload['jpEmployeeRate'] ?? 1),
            'jp_company_rate' => (float) ($payload['jpCompanyRate'] ?? 2),
            'jkk_company_rate' => (float) ($payload['jkkCompanyRate'] ?? 0.24),
            'jkm_company_rate' => (float) ($payload['jkmCompanyRate'] ?? 0.3),
            'status' => $payload['status'] ?? BpjsConfig::STATUS_ACTIVE,
        ]);
    }
}
