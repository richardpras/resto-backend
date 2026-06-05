<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Accounting\Domain\JournalEntry;
use App\Models\Modules\HR\Domain\PayrollRun;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Legacy payroll v1 journal posting. */
class LegacyPayrollPostingService
{
    public function postRunToJournal(
        int $runId,
        int $actorUserId,
        string $cashAccountCode = '1001',
        string $salaryExpenseAccountCode = '5001'
    ): Journal {
        return DB::transaction(function () use ($runId, $actorUserId, $cashAccountCode, $salaryExpenseAccountCode) {
            $run = PayrollRun::query()->with('lines')->find($runId);
            abort_if($run === null, Response::HTTP_NOT_FOUND, 'Payroll run not found.');
            abort_if($run->lines->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Payroll run has no lines to post.');

            $alreadyPosted = Journal::query()
                ->where('source_type', 'payroll_run')
                ->where('source_id', $run->id)
                ->exists();
            abort_if($alreadyPosted, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payroll run already posted to journal.');

            $cashAccount = Account::query()->where('code', $cashAccountCode)->first();
            $salaryExpenseAccount = Account::query()->where('code', $salaryExpenseAccountCode)->first();
            abort_if(
                $cashAccount === null || $salaryExpenseAccount === null,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Required accounts for payroll posting were not found.'
            );

            $journal = Journal::query()->create([
                'tenant_id' => null,
                'journal_no' => 'JRN-PAYRUN-'.now()->format('YmdHis').'-'.$run->id,
                'source_type' => 'payroll_run',
                'source_id' => $run->id,
                'journal_date' => now()->toDateString(),
                'status' => 'posted',
                'description' => 'Payroll run posting '.$run->period,
                'outlet' => $run->outlet,
                'created_by' => $actorUserId,
            ]);

            $lineNo = 1;
            foreach ($run->lines as $line) {
                $amount = (float) $line->net_salary;
                JournalEntry::query()->create([
                    'journal_id' => $journal->id,
                    'account_id' => $salaryExpenseAccount->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Payroll expense employee #'.$line->employee_id,
                    'line_no' => $lineNo++,
                ]);
                JournalEntry::query()->create([
                    'journal_id' => $journal->id,
                    'account_id' => $cashAccount->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Payroll cash out employee #'.$line->employee_id,
                    'line_no' => $lineNo++,
                ]);
            }

            return $journal;
        });
    }
}
