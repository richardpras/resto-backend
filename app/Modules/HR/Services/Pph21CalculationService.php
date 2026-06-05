<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeTaxProfile;
use App\Models\Modules\HR\Domain\Pph21Bracket;
use App\Models\Modules\HR\Domain\Pph21Config;
use Illuminate\Support\Collection;

class Pph21CalculationService
{
    /**
     * @return array<string, float|string|null>
     */
    public function calculateForEmployee(
        int $employeeId,
        float $monthlyGrossSalary,
        float $monthlyBpjsEmployeeDeduction,
        string $asOfDate,
    ): array {
        $zeros = $this->zeroAmounts();

        $profile = EmployeeTaxProfile::query()->where('employee_id', $employeeId)->first();
        if ($profile === null || ! $profile->pph21_enabled) {
            return $zeros;
        }

        $config = $this->resolveConfigForDate($asOfDate);
        if ($config === null) {
            return $zeros;
        }

        $config->loadMissing('brackets');
        if ($config->brackets->isEmpty()) {
            return $zeros;
        }

        $monthlyGrossSalary = round($monthlyGrossSalary, 2);
        $monthlyBpjsEmployeeDeduction = round($monthlyBpjsEmployeeDeduction, 2);

        $monthlyTaxableIncome = round($monthlyGrossSalary - $monthlyBpjsEmployeeDeduction, 2);
        $annualGross = round($monthlyGrossSalary * 12, 2);
        $annualBpjsDeduction = round($monthlyBpjsEmployeeDeduction * 12, 2);
        $annualTaxableIncome = round($annualGross - $annualBpjsDeduction, 2);

        $ptkpStatus = (string) $profile->ptkp_status;
        $ptkpAmount = round($config->ptkpForStatus($ptkpStatus), 2);
        $annualPkp = round(max(0, $annualTaxableIncome - $ptkpAmount), 2);
        $annualPph21 = $this->calculateProgressiveTax($annualPkp, $config->brackets);
        $monthlyPph21 = round($annualPph21 / 12, 2);

        return [
            'taxable_income' => $monthlyTaxableIncome,
            'annual_taxable_income' => $annualTaxableIncome,
            'annual_pkp' => $annualPkp,
            'annual_pph21' => $annualPph21,
            'pph21_amount' => $monthlyPph21,
            'ptkp_status' => $ptkpStatus,
            'ptkp_amount' => $ptkpAmount,
            'npwp_number' => $profile->npwp_number,
        ];
    }

    public function resolveConfigForDate(string $asOfDate): ?Pph21Config
    {
        return Pph21Config::query()
            ->with('brackets')
            ->where('is_active', true)
            ->where('effective_date', '<=', $asOfDate)
            ->orderByDesc('effective_date')
            ->first();
    }

    /**
     * @param  Collection<int, Pph21Bracket>  $brackets
     */
    public function calculateProgressiveTax(float $pkp, Collection $brackets): float
    {
        if ($pkp <= 0) {
            return 0.0;
        }

        $tax = 0.0;
        $sorted = $brackets->sortBy('income_from')->values();

        foreach ($sorted as $bracket) {
            $from = (float) $bracket->income_from;
            $to = $bracket->income_to !== null ? (float) $bracket->income_to : null;

            if ($pkp <= $from) {
                break;
            }

            $upper = $to !== null ? min($pkp, $to) : $pkp;
            $taxableInBracket = $upper - $from;

            if ($taxableInBracket > 0) {
                $tax += $taxableInBracket * ((float) $bracket->tax_rate / 100);
            }
        }

        return round($tax, 2);
    }

    /**
     * @return array<string, float|string|null>
     */
    private function zeroAmounts(): array
    {
        return [
            'taxable_income' => 0.0,
            'annual_taxable_income' => 0.0,
            'annual_pkp' => 0.0,
            'annual_pph21' => 0.0,
            'pph21_amount' => 0.0,
            'ptkp_status' => null,
            'ptkp_amount' => 0.0,
            'npwp_number' => null,
        ];
    }
}
