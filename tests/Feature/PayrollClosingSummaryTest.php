<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollClosingSummaryTest extends TestCase
{
    use FinalizedPayrollRunFixture;
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_closing_summary_totals_match_run_items(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $items = PayrollRunItemV2::query()->where('payroll_run_id', $runId)->get();

        $res = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/closing-summary')->assertOk();
        $totals = $res->json('data.totals');

        $this->assertEquals($items->count(), $totals['employeeCount']);
        $this->assertEquals(round((float) $items->sum('gross_salary'), 2), $totals['grossPayroll']);
        $this->assertEquals(round((float) $items->sum('net_salary'), 2), $totals['netPayroll']);
        $this->assertEquals(round((float) $items->sum('loan_deduction'), 2), $totals['totalLoans']);
        $this->assertEquals(round((float) $items->sum('cash_advance_deduction'), 2), $totals['totalCashAdvance']);
        $this->assertEquals(round((float) $items->sum('reimbursement_earning'), 2), $totals['totalReimbursement']);
        $this->assertEquals(round((float) $items->sum('pph21_amount'), 2), $totals['totalPPh21']);
        $this->assertSame('pending', $totals['paymentStatus']);
        $this->assertSame('open', $totals['closedStatus']);

        $this->assertNotEmpty($res->json('data.auditTrail'));
    }
}
