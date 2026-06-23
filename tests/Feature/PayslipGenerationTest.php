<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\RendersPendingPayslips;
use Tests\TestCase;

class PayslipGenerationTest extends TestCase
{
    use FinalizedPayrollRunFixture;
    use HrmApiFixture;
    use RefreshDatabase;
    use RendersPendingPayslips;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_generate_payslips_from_finalized_run(): void
    {
        [$employee, , $run] = $this->seedFinalizedPayrollRun();

        $res = $this->postJson('/api/v1/payslips/generate', [
            'payrollRunId' => $run->id,
        ])->assertCreated();

        $this->assertSame(1, (int) $res->json('meta.count'));
        $data = $res->json('data.0');
        $this->assertSame('draft', $data['status']);
        $this->assertFalse($data['pdfAvailable']);
        $this->assertStringContainsString('PS-', $data['payslipNo']);
        $this->assertSame('queued', $res->json('meta.generation.phase'));

        $this->renderPendingPayslipsForRun($run->id);

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();
        $this->assertNotNull($payslip);
        $this->assertSame(PayrollPayslip::STATUS_GENERATED, $payslip->status);
        $this->assertNotNull($payslip->pdf_path);
        $this->assertEquals((int) $employee->id, (int) $payslip->employee_id);
        $this->assertNotNull($payslip->breakdown_json['calculation']);
    }

    public function test_non_finalized_run_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, $period] = $this->seedLockedPreparationWithEmployee();

        $runRes = $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertCreated();

        $this->postJson('/api/v1/payslips/generate', [
            'payrollRunId' => (int) $runRes->json('data.id'),
        ])->assertStatus(422);
    }

    public function test_snapshot_preserved_when_item_values_change(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();
        $originalNet = (float) $payslip->net_salary;

        PayrollRunItemV2::query()->where('payroll_run_id', $run->id)->update([
            'net_salary' => 1,
            'gross_salary' => 1,
        ]);

        $payslip->refresh();
        $this->assertEquals($originalNet, (float) $payslip->net_salary);
    }
}
