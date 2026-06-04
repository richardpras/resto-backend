<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\BpjsConfig;
use App\Models\Modules\HR\Domain\BpjsProfile;

class BpjsCalculationService
{
    /**
     * @return array<string, float>
     */
    public function calculateForEmployee(int $employeeId, float $fallbackSalaryBase, string $asOfDate): array
    {
        $zeros = $this->zeroAmounts();

        $profile = BpjsProfile::query()->where('employee_id', $employeeId)->first();
        if ($profile === null) {
            return $zeros;
        }

        $config = $this->resolveConfigForDate($asOfDate);
        if ($config === null) {
            return $zeros;
        }

        $base = (float) ($profile->bpjs_salary_base ?? 0);
        if ($base <= 0) {
            $base = $fallbackSalaryBase;
        }
        if ($base <= 0) {
            return $zeros;
        }

        $rates = $this->resolveRates($config, $profile);

        $amounts = $zeros;
        if ($profile->bpjs_kesehatan_enabled) {
            $amounts['bpjs_kesehatan_employee'] = $this->amount($base, $rates['kesehatan_employee_rate']);
            $amounts['bpjs_kesehatan_company'] = $this->amount($base, $rates['kesehatan_company_rate']);
        }

        if ($profile->bpjs_tk_enabled) {
            $amounts['bpjs_jht_employee'] = $this->amount($base, $rates['jht_employee_rate']);
            $amounts['bpjs_jht_company'] = $this->amount($base, $rates['jht_company_rate']);
            $amounts['bpjs_jp_employee'] = $this->amount($base, $rates['jp_employee_rate']);
            $amounts['bpjs_jp_company'] = $this->amount($base, $rates['jp_company_rate']);
            $amounts['bpjs_jkk_company'] = $this->amount($base, $rates['jkk_company_rate']);
            $amounts['bpjs_jkm_company'] = $this->amount($base, $rates['jkm_company_rate']);
        }

        return $amounts;
    }

    public function employeeDeductionTotal(array $amounts): float
    {
        return round(
            (float) ($amounts['bpjs_kesehatan_employee'] ?? 0)
            + (float) ($amounts['bpjs_jht_employee'] ?? 0)
            + (float) ($amounts['bpjs_jp_employee'] ?? 0),
            2,
        );
    }

    public function resolveConfigForDate(string $asOfDate): ?BpjsConfig
    {
        return BpjsConfig::query()
            ->where('status', BpjsConfig::STATUS_ACTIVE)
            ->where('effective_date', '<=', $asOfDate)
            ->orderByDesc('effective_date')
            ->first();
    }

    /**
     * @return array<string, float>
     */
    private function resolveRates(BpjsConfig $config, BpjsProfile $profile): array
    {
        return [
            'kesehatan_employee_rate' => (float) ($profile->kesehatan_employee_rate_override ?? $config->kesehatan_employee_rate),
            'kesehatan_company_rate' => (float) ($profile->kesehatan_company_rate_override ?? $config->kesehatan_company_rate),
            'jht_employee_rate' => (float) ($profile->jht_employee_rate_override ?? $config->jht_employee_rate),
            'jht_company_rate' => (float) ($profile->jht_company_rate_override ?? $config->jht_company_rate),
            'jp_employee_rate' => (float) ($profile->jp_employee_rate_override ?? $config->jp_employee_rate),
            'jp_company_rate' => (float) ($profile->jp_company_rate_override ?? $config->jp_company_rate),
            'jkk_company_rate' => (float) ($profile->jkk_company_rate_override ?? $config->jkk_company_rate),
            'jkm_company_rate' => (float) ($profile->jkm_company_rate_override ?? $config->jkm_company_rate),
        ];
    }

    private function amount(float $base, float $ratePercent): float
    {
        return round($base * ($ratePercent / 100), 2);
    }

    /**
     * @return array<string, float>
     */
    private function zeroAmounts(): array
    {
        return [
            'bpjs_kesehatan_employee' => 0.0,
            'bpjs_kesehatan_company' => 0.0,
            'bpjs_jht_employee' => 0.0,
            'bpjs_jht_company' => 0.0,
            'bpjs_jp_employee' => 0.0,
            'bpjs_jp_company' => 0.0,
            'bpjs_jkk_company' => 0.0,
            'bpjs_jkm_company' => 0.0,
        ];
    }
}
