<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\HR\Domain\PayrollPosting;
use App\Models\Modules\HR\Domain\PayrollRunAudit;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PayrollPostingService
{
    /** @var array<string, array{fallback: list<string>, types: list<string>}> */
    private const ACCOUNT_MAP = [
        'payroll_expense' => ['fallback' => ['6100', '5001'], 'types' => ['expense']],
        'salary_payable' => ['fallback' => ['2150', '2100'], 'types' => ['liability']],
        'pph21_payable' => ['fallback' => ['2160'], 'types' => ['liability']],
        'bpjs_payable' => ['fallback' => ['2170'], 'types' => ['liability']],
        'loan_receivable' => ['fallback' => ['1210', '1200'], 'types' => ['asset']],
        'cash_advance_recovery' => ['fallback' => ['1220', '1200'], 'types' => ['asset']],
        'other_deductions' => ['fallback' => ['2180', '2190'], 'types' => ['liability']],
    ];

    public function __construct(
        private readonly PayrollRunServiceV2 $payrollRuns,
        private readonly JournalPostingService $journalPosting,
        private readonly PayrollRunAuditService $audits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(?User $user, int $runId): array
    {
        $run = $this->assertPostableRun($user, $runId);
        $built = $this->buildJournalLines($run);

        return [
            'payrollRunId' => (int) $run->id,
            'lines' => $built['lines'],
            'totals' => $built['totals'],
            'balanced' => $built['balanced'],
            'postingStatus' => $this->findPosting($runId)?->posting_status ?? PayrollPosting::STATUS_DRAFT,
        ];
    }

    public function post(?User $user, int $runId): PayrollPosting
    {
        $run = $this->assertPostableRun($user, $runId);

        $existing = $this->findPosting($runId);
        if ($existing !== null && $existing->posting_status === PayrollPosting::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'payrollRunId' => ['Payroll run has already been posted.'],
            ]);
        }
        if ($existing !== null && $existing->posting_status === PayrollPosting::STATUS_REVERSED) {
            throw ValidationException::withMessages([
                'payrollRunId' => ['Reversed payroll runs cannot be posted again. Create a new payroll run.'],
            ]);
        }

        $built = $this->buildJournalLines($run);
        if (! $built['balanced']) {
            throw ValidationException::withMessages([
                'journal' => ['Payroll journal preview is not balanced.'],
            ]);
        }

        return DB::transaction(function () use ($run, $user, $built, $existing) {
            $period = $run->preparationPeriod;
            $journalDate = $period?->period_end?->toDateString() ?? now()->toDateString();

            $journal = $this->journalPosting->post([
                'outlet_id' => (int) $run->outlet_id,
                'source_type' => 'payroll_run_v2',
                'source_id' => (string) $run->id,
                'journal_date' => $journalDate,
                'description' => sprintf(
                    'Payroll posting run #%d (%s — %s)',
                    $run->id,
                    $period?->period_start?->toDateString() ?? '',
                    $period?->period_end?->toDateString() ?? '',
                ),
                'posting_key' => 'payroll-run-v2-'.$run->id,
                'scope' => 'payroll_run_v2.'.$run->id,
                'posted_by' => $user?->id,
                'lines' => $built['journalLines'],
            ]);

            if ($existing !== null) {
                $existing->update([
                    'journal_entry_id' => $journal->id,
                    'posting_status' => PayrollPosting::STATUS_POSTED,
                    'posted_at' => now(),
                    'reversed_at' => null,
                    'reversed_by' => null,
                ]);
                $posting = $existing->refresh();
            } else {
                $posting = PayrollPosting::query()->create([
                    'payroll_run_id' => $run->id,
                    'journal_entry_id' => $journal->id,
                    'posting_status' => PayrollPosting::STATUS_POSTED,
                    'posted_at' => now(),
                ]);
            }

            $this->audits->record(
                (int) $run->id,
                PayrollRunAudit::ACTION_POSTING_CREATED,
                $user,
                'Journal #'.$journal->journal_no,
            );

            return $posting->load(['journal', 'payrollRun']);
        });
    }

    public function reverse(?User $user, int $runId, ?string $notes = null): PayrollPosting
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $posting = $this->findPosting($runId);

        if ($posting === null || $posting->posting_status !== PayrollPosting::STATUS_POSTED) {
            throw ValidationException::withMessages([
                'posting' => ['Only posted payroll runs can be reversed.'],
            ]);
        }

        $journal = $posting->journal;
        abort_if($journal === null, Response::HTTP_NOT_FOUND, 'Posted journal not found.');

        return DB::transaction(function () use ($posting, $journal, $user, $notes, $run) {
            $this->journalPosting->reverse($journal, $user, 'payroll-run-v2-reverse-'.$run->id, $notes);

            $posting->update([
                'posting_status' => PayrollPosting::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $user?->id,
                'notes' => $notes ?? $posting->notes,
            ]);

            $this->audits->record(
                (int) $run->id,
                PayrollRunAudit::ACTION_POSTING_REVERSED,
                $user,
                $notes,
            );

            return $posting->refresh()->load(['journal', 'payrollRun']);
        });
    }

    public function status(?User $user, int $runId): ?PayrollPosting
    {
        $this->payrollRuns->findAccessible($user, $runId);

        return $this->findPosting($runId)?->load(['journal', 'payrollRun.preparationPeriod']);
    }

    private function assertPostableRun(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->payrollRuns->findAccessible($user, $runId);
        $run->load(['preparationPeriod', 'items']);

        if ($run->status !== PayrollRunV2::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'status' => ['Only closed payroll runs can be posted to accounting.'],
            ]);
        }

        if ($run->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Payroll run has no line items to post.'],
            ]);
        }

        return $run;
    }

    private function findPosting(int $runId): ?PayrollPosting
    {
        return PayrollPosting::query()
            ->where('payroll_run_id', $runId)
            ->first();
    }

    /**
     * @return array{lines: list<array<string, mixed>>, journalLines: list<array<string, mixed>>, totals: array<string, float>, balanced: bool}
     */
    private function buildJournalLines(PayrollRunV2 $run): array
    {
        $items = PayrollRunItemV2::query()->where('payroll_run_id', $run->id)->get();
        $accounts = $this->resolveAccounts((int) $run->outlet_id);

        $gross = round((float) $items->sum('gross_salary'), 2);
        $employerBpjs = round(
            (float) $items->sum('bpjs_kesehatan_company')
            + (float) $items->sum('bpjs_jht_company')
            + (float) $items->sum('bpjs_jp_company')
            + (float) $items->sum('bpjs_jkk_company')
            + (float) $items->sum('bpjs_jkm_company'),
            2,
        );
        $net = round((float) $items->sum('net_salary'), 2);
        $pph21 = round((float) $items->sum('pph21_amount'), 2);
        $employeeBpjs = round(
            (float) $items->sum('bpjs_kesehatan_employee')
            + (float) $items->sum('bpjs_jht_employee')
            + (float) $items->sum('bpjs_jp_employee'),
            2,
        );
        $bpjsPayable = round($employeeBpjs + $employerBpjs, 2);
        $loans = round((float) $items->sum('loan_deduction'), 2);
        $cashAdvance = round((float) $items->sum('cash_advance_deduction'), 2);
        $adjustmentDeduction = round((float) $items->sum('adjustment_deduction'), 2);
        $totalDeductions = round((float) $items->sum('total_deductions'), 2);
        $otherDeductions = round(
            max(0, $totalDeductions - $pph21 - $employeeBpjs - $loans - $cashAdvance - $adjustmentDeduction),
            2,
        );

        $payrollExpense = round($gross + $employerBpjs, 2);

        $components = [
            ['key' => 'payroll_expense', 'debit' => $payrollExpense, 'credit' => 0, 'memo' => 'Payroll expense (gross + employer BPJS)'],
            ['key' => 'salary_payable', 'debit' => 0, 'credit' => $net, 'memo' => 'Salary payable (net payroll)'],
            ['key' => 'pph21_payable', 'debit' => 0, 'credit' => $pph21, 'memo' => 'PPh21 payable'],
            ['key' => 'bpjs_payable', 'debit' => 0, 'credit' => $bpjsPayable, 'memo' => 'BPJS payable'],
            ['key' => 'loan_receivable', 'debit' => 0, 'credit' => $loans, 'memo' => 'Loan receivable recovery'],
            ['key' => 'cash_advance_recovery', 'debit' => 0, 'credit' => $cashAdvance, 'memo' => 'Cash advance recovery'],
            ['key' => 'other_deductions', 'debit' => 0, 'credit' => round($adjustmentDeduction + $otherDeductions, 2), 'memo' => 'Other payroll deductions'],
        ];

        $lines = [];
        $journalLines = [];
        foreach ($components as $component) {
            $amount = max((float) $component['debit'], (float) $component['credit']);
            if ($amount <= 0) {
                continue;
            }
            $account = $accounts[$component['key']];
            $lines[] = [
                'accountId' => (int) $account->id,
                'accountCode' => $account->code,
                'accountName' => $account->name,
                'debit' => (float) $component['debit'],
                'credit' => (float) $component['credit'],
                'memo' => $component['memo'],
            ];
            $journalLines[] = [
                'account_id' => (int) $account->id,
                'debit' => (float) $component['debit'],
                'credit' => (float) $component['credit'],
                'memo' => $component['memo'],
            ];
        }

        $debitTotal = round((float) collect($lines)->sum('debit'), 2);
        $creditTotal = round((float) collect($lines)->sum('credit'), 2);

        return [
            'lines' => $lines,
            'journalLines' => $journalLines,
            'totals' => [
                'debit' => $debitTotal,
                'credit' => $creditTotal,
                'grossPayroll' => $gross,
                'employerBpjs' => $employerBpjs,
                'netPayroll' => $net,
            ],
            'balanced' => $debitTotal === $creditTotal && $debitTotal > 0,
        ];
    }

    /**
     * @return array<string, Account>
     */
    private function resolveAccounts(int $outletId): array
    {
        $resolved = [];
        foreach (self::ACCOUNT_MAP as $key => $config) {
            $account = $this->resolveAccount($config['fallback'], $config['types'], $outletId);
            if ($account === null) {
                throw ValidationException::withMessages([
                    'accounts' => ["Accounting account mapping missing for {$key}."],
                ]);
            }
            $resolved[$key] = $account;
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $fallbackCodes
     * @param  list<string>  $types
     */
    private function resolveAccount(array $fallbackCodes, array $types, int $outletId): ?Account
    {
        $query = Account::query()->whereIn('type', $types)->where('is_active', true);
        if ($outletId > 0) {
            $query->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        foreach ($fallbackCodes as $code) {
            $candidate = (clone $query)->where('code', $code)->orderByRaw('outlet_id is null')->first();
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return (clone $query)->orderBy('id')->first();
    }
}
