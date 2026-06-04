<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\BpjsConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BpjsConfig */
class BpjsConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'effectiveDate' => $this->effective_date?->toDateString(),
            'kesehatanEmployeeRate' => (float) $this->kesehatan_employee_rate,
            'kesehatanCompanyRate' => (float) $this->kesehatan_company_rate,
            'jhtEmployeeRate' => (float) $this->jht_employee_rate,
            'jhtCompanyRate' => (float) $this->jht_company_rate,
            'jpEmployeeRate' => (float) $this->jp_employee_rate,
            'jpCompanyRate' => (float) $this->jp_company_rate,
            'jkkCompanyRate' => (float) $this->jkk_company_rate,
            'jkmCompanyRate' => (float) $this->jkm_company_rate,
            'status' => $this->status,
        ];
    }
}
