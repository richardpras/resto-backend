<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayslipPublicationTest extends TestCase
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

    public function test_publish_after_generation(): void
    {
        [$employee, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();
        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();

        $this->postJson('/api/v1/payslips/'.$payslip->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $payslip->refresh();
        $this->assertNotNull($payslip->published_at);
    }

    public function test_employee_payslip_history(): void
    {
        [$employee, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();

        $res = $this->getJson('/api/v1/employees/'.$employee->id.'/payslips')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('generated', $res->json('data.0.status'));
    }

    public function test_list_payslips_with_filters(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();

        $res = $this->getJson('/api/v1/payslips?payrollRunId='.$run->id.'&status=generated')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }
}
