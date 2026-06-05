<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

class PayslipPdfService
{
    public function renderAndStore(PayrollPayslip $payslip): string
    {
        $html = $this->buildHtml($payslip);
        $binary = $this->renderHtml($html);

        $path = $this->storagePathFor($payslip);
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    public function absolutePath(?string $pdfPath): ?string
    {
        if ($pdfPath === null || $pdfPath === '') {
            return null;
        }

        $full = Storage::disk('local')->path($pdfPath);

        return is_file($full) ? $full : null;
    }

    private function buildHtml(PayrollPayslip $payslip): string
    {
        $breakdown = $payslip->breakdown_json ?? [];
        $calc = $breakdown['calculation'] ?? [];
        $bpjs = is_array($calc['bpjs'] ?? null) ? $calc['bpjs'] : [];
        $pph21 = is_array($calc['pph21'] ?? null) ? $calc['pph21'] : [];

        return View::make('payslips.pdf', [
            'payslipNo' => $payslip->payslip_no,
            'companyName' => (string) ($breakdown['companyName'] ?? 'Company'),
            'outletName' => (string) ($breakdown['outletName'] ?? ''),
            'periodLabel' => (string) ($breakdown['periodLabel'] ?? ''),
            'generatedAt' => (string) ($breakdown['generatedAt'] ?? now()->toDateTimeString()),
            'employeeNo' => (string) ($breakdown['employeeNo'] ?? ''),
            'employeeName' => (string) ($breakdown['employeeName'] ?? ''),
            'position' => (string) ($breakdown['position'] ?? ''),
            'basicSalary' => (float) ($calc['basicSalary'] ?? 0),
            'allowance' => (float) ($calc['allowance'] ?? 0),
            'overtimePay' => (float) ($calc['overtimePay'] ?? 0),
            'adjustmentEarning' => (float) ($calc['adjustmentEarning'] ?? 0),
            'reimbursementEarning' => (float) ($calc['reimbursementEarning'] ?? 0),
            'defaultDeduction' => (float) ($calc['defaultDeduction'] ?? 0),
            'unpaidLeaveDeduction' => (float) ($calc['unpaidLeaveDeduction'] ?? 0),
            'attendanceDeduction' => (float) ($calc['attendanceDeduction'] ?? 0),
            'loanDeduction' => (float) ($calc['loanDeduction'] ?? 0),
            'cashAdvanceDeduction' => (float) ($calc['cashAdvanceDeduction'] ?? 0),
            'adjustmentDeduction' => (float) ($calc['adjustmentDeduction'] ?? 0),
            'bpjsKesehatanEmployee' => (float) ($bpjs['bpjs_kesehatan_employee'] ?? 0),
            'bpjsJhtEmployee' => (float) ($bpjs['bpjs_jht_employee'] ?? 0),
            'bpjsJpEmployee' => (float) ($bpjs['bpjs_jp_employee'] ?? 0),
            'bpjsKesehatanCompany' => (float) ($bpjs['bpjs_kesehatan_company'] ?? 0),
            'bpjsJhtCompany' => (float) ($bpjs['bpjs_jht_company'] ?? 0),
            'bpjsJpCompany' => (float) ($bpjs['bpjs_jp_company'] ?? 0),
            'bpjsJkkCompany' => (float) ($bpjs['bpjs_jkk_company'] ?? 0),
            'bpjsJkmCompany' => (float) ($bpjs['bpjs_jkm_company'] ?? 0),
            'pph21Amount' => (float) ($pph21['pph21_amount'] ?? 0),
            'ptkpStatus' => (string) ($pph21['ptkp_status'] ?? ''),
            'annualPkp' => (float) ($pph21['annual_pkp'] ?? 0),
            'annualPph21' => (float) ($pph21['annual_pph21'] ?? 0),
            'grossSalary' => (float) $payslip->gross_salary,
            'totalDeductions' => (float) $payslip->total_deductions,
            'netSalary' => (float) $payslip->net_salary,
        ])->render();
    }

    private function renderHtml(string $html): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function storagePathFor(PayrollPayslip $payslip): string
    {
        $breakdown = $payslip->breakdown_json ?? [];
        $year = (string) ($breakdown['periodYear'] ?? now()->format('Y'));
        $month = (string) ($breakdown['periodMonth'] ?? now()->format('m'));

        return sprintf('payslips/%s/%s/%s.pdf', $year, $month, $payslip->payslip_no);
    }
}
