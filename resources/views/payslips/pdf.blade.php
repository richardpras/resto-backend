<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $payslipNo }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 20px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .meta { color: #444; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; }
        th { width: 55%; font-weight: normal; color: #333; }
        td.amount { text-align: right; font-weight: bold; }
        .totals td { border-top: 2px solid #333; font-size: 13px; }
        .signatures { margin-top: 48px; display: table; width: 100%; }
        .signatures .col { display: table-cell; width: 50%; vertical-align: top; }
        .sign-line { margin-top: 56px; border-top: 1px solid #333; width: 200px; }
        .muted { color: #666; font-size: 11px; }
    </style>
</head>
<body>
    <h1>{{ $companyName }}</h1>
    <div class="meta">
        <div>{{ $outletName }}</div>
        <div><strong>Payslip:</strong> {{ $payslipNo }}</div>
        <div><strong>Period:</strong> {{ $periodLabel }}</div>
        <div><strong>Generated:</strong> {{ $generatedAt }}</div>
    </div>

    <h2>Employee</h2>
    <table>
        <tr><th>Employee ID</th><td>{{ $employeeNo }}</td></tr>
        <tr><th>Employee Name</th><td>{{ $employeeName }}</td></tr>
        <tr><th>Position</th><td>{{ $position }}</td></tr>
        <tr><th>Outlet</th><td>{{ $outletName }}</td></tr>
    </table>

    <h2>Earnings</h2>
    <table>
        <tr><th>Basic Salary</th><td class="amount">IDR {{ number_format($basicSalary, 0, ',', '.') }}</td></tr>
        <tr><th>Allowance</th><td class="amount">IDR {{ number_format($allowance, 0, ',', '.') }}</td></tr>
        <tr><th>Overtime Pay</th><td class="amount">IDR {{ number_format($overtimePay, 0, ',', '.') }}</td></tr>
        <tr><th>Adjustment Earnings</th><td class="amount">IDR {{ number_format($adjustmentEarning, 0, ',', '.') }}</td></tr>
        @if(($reimbursementEarning ?? 0) > 0)
        <tr><th>Reimbursement</th><td class="amount">IDR {{ number_format($reimbursementEarning, 0, ',', '.') }}</td></tr>
        @endif
    </table>

    <h2>Deductions</h2>
    <table>
        <tr><th>Default Deduction</th><td class="amount">IDR {{ number_format($defaultDeduction, 0, ',', '.') }}</td></tr>
        <tr><th>Unpaid Leave Deduction</th><td class="amount">IDR {{ number_format($unpaidLeaveDeduction, 0, ',', '.') }}</td></tr>
        <tr><th>Attendance Deduction</th><td class="amount">IDR {{ number_format($attendanceDeduction, 0, ',', '.') }}</td></tr>
        <tr><th>Loan Deduction</th><td class="amount">IDR {{ number_format($loanDeduction, 0, ',', '.') }}</td></tr>
        <tr><th>Cash Advance Deduction</th><td class="amount">IDR {{ number_format($cashAdvanceDeduction, 0, ',', '.') }}</td></tr>
        <tr><th>Adjustment Deduction</th><td class="amount">IDR {{ number_format($adjustmentDeduction, 0, ',', '.') }}</td></tr>
        @if($bpjsKesehatanEmployee > 0)
        <tr><th>BPJS Kesehatan (Employee)</th><td class="amount">IDR {{ number_format($bpjsKesehatanEmployee, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJhtEmployee > 0)
        <tr><th>JHT (Employee)</th><td class="amount">IDR {{ number_format($bpjsJhtEmployee, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJpEmployee > 0)
        <tr><th>JP (Employee)</th><td class="amount">IDR {{ number_format($bpjsJpEmployee, 0, ',', '.') }}</td></tr>
        @endif
        @if(($pph21Amount ?? 0) > 0)
        <tr><th>PPh21</th><td class="amount">IDR {{ number_format($pph21Amount, 0, ',', '.') }}</td></tr>
        @endif
    </table>

    @if(($pph21Amount ?? 0) > 0)
    <h2>Tax</h2>
    <table>
        <tr><th>PTKP Status</th><td>{{ $ptkpStatus }}</td></tr>
        <tr><th>Annual PKP</th><td class="amount">IDR {{ number_format($annualPkp, 0, ',', '.') }}</td></tr>
        <tr><th>Estimated Annual Tax</th><td class="amount">IDR {{ number_format($annualPph21, 0, ',', '.') }}</td></tr>
        <tr><th>Monthly PPh21</th><td class="amount">IDR {{ number_format($pph21Amount, 0, ',', '.') }}</td></tr>
    </table>
    @endif

    @if($bpjsKesehatanCompany > 0 || $bpjsJhtCompany > 0 || $bpjsJpCompany > 0 || $bpjsJkkCompany > 0 || $bpjsJkmCompany > 0)
    <h2>Employer Contributions</h2>
    <p class="muted">Employer BPJS costs are informational only and do not reduce net salary.</p>
    <table>
        @if($bpjsKesehatanCompany > 0)
        <tr><th>BPJS Kesehatan (Employer)</th><td class="amount">IDR {{ number_format($bpjsKesehatanCompany, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJhtCompany > 0)
        <tr><th>JHT (Employer)</th><td class="amount">IDR {{ number_format($bpjsJhtCompany, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJpCompany > 0)
        <tr><th>JP (Employer)</th><td class="amount">IDR {{ number_format($bpjsJpCompany, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJkkCompany > 0)
        <tr><th>JKK (Employer)</th><td class="amount">IDR {{ number_format($bpjsJkkCompany, 0, ',', '.') }}</td></tr>
        @endif
        @if($bpjsJkmCompany > 0)
        <tr><th>JKM (Employer)</th><td class="amount">IDR {{ number_format($bpjsJkmCompany, 0, ',', '.') }}</td></tr>
        @endif
    </table>
    @endif

    <h2>Totals</h2>
    <table class="totals">
        <tr><th>Gross Salary</th><td class="amount">IDR {{ number_format($grossSalary, 0, ',', '.') }}</td></tr>
        <tr><th>Total Deductions</th><td class="amount">IDR {{ number_format($totalDeductions, 0, ',', '.') }}</td></tr>
        <tr><th>Net Salary</th><td class="amount">IDR {{ number_format($netSalary, 0, ',', '.') }}</td></tr>
    </table>

    <div class="signatures">
        <div class="col">
            <div>HR / Payroll</div>
            <div class="sign-line"></div>
        </div>
        <div class="col">
            <div>Employee</div>
            <div class="sign-line"></div>
        </div>
    </div>
</body>
</html>
