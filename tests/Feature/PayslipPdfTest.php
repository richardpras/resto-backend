<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPayslip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\RendersPendingPayslips;
use Tests\TestCase;

class PayslipPdfTest extends TestCase
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
        Storage::fake('local');
    }

    public function test_pdf_file_created_on_generate(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();
        $this->renderPendingPayslipsForRun($run->id);

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();
        $this->assertNotNull($payslip->pdf_path);
        Storage::disk('local')->assertExists($payslip->pdf_path);
    }

    public function test_regenerate_pdf(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();
        $this->renderPendingPayslipsForRun($run->id);

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();
        $oldPath = $payslip->pdf_path;

        $this->postJson('/api/v1/payslips/'.$payslip->id.'/regenerate')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $payslip->refresh();
        $this->assertNull($payslip->pdf_path);

        $this->renderPendingPayslipsForRun($run->id);

        $payslip->refresh();
        $this->assertNotNull($payslip->pdf_path);
        Storage::disk('local')->assertExists($payslip->pdf_path);
        $this->assertSame('generated', $payslip->status);
        $this->assertNotNull($oldPath);
    }

    public function test_download_endpoint_returns_pdf(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();
        $this->renderPendingPayslipsForRun($run->id);

        $payslip = PayrollPayslip::query()->where('payroll_run_id', $run->id)->first();

        $response = $this->get('/api/v1/payslips/'.$payslip->id.'/download');
        $response->assertOk();
        $this->assertStringContainsString('pdf', strtolower((string) $response->headers->get('Content-Type')));
    }
}
