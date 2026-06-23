<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayslipRenderPendingCommandTest extends TestCase
{
    use FinalizedPayrollRunFixture;
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('local');
    }

    public function test_command_renders_draft_payslips_with_limit(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();
        $this->assertSame(PayrollPayslip::STATUS_DRAFT, $payslip->status);

        Artisan::call('payslip:render-pending', ['--run' => $run->id, '--limit' => 1]);

        $payslip->refresh();
        $this->assertSame(PayrollPayslip::STATUS_GENERATED, $payslip->status);
        $this->assertNotNull($payslip->pdf_path);
        Storage::disk('local')->assertExists($payslip->pdf_path);
    }

    public function test_command_succeeds_when_no_pending_payslips(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->artisan('payslip:render-pending', ['--run' => $run->id])
            ->assertExitCode(0);
    }
}
