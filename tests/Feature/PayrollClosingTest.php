<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollRunV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollClosingTest extends TestCase
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

    public function test_payment_lifecycle_transitions(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')
            ->assertOk()
            ->assertJsonPath('data.status', 'processing_payment')
            ->assertJsonPath('data.paymentStatus', 'processing');

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid', [
            'paidAt' => '2026-10-31',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paymentStatus', 'paid');

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close', [
            'notes' => 'October payroll closed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.isClosed', true);

        $run->refresh();
        $this->assertNotNull($run->closed_at);
        $this->assertSame('October payroll closed', $run->closed_notes);
    }

    public function test_reopen_closed_run(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertOk();

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/reopen')
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.isClosed', false);

        $run->refresh();
        $this->assertNull($run->closed_at);
        $this->assertNull($run->closed_notes);
    }

    public function test_invalid_transitions_rejected(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertStatus(422);
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertStatus(422);

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertStatus(422);
    }

    public function test_closed_run_is_immutable(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertOk();

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/calculate')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/approve')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->patchJson('/api/v1/payroll-runs-v2/'.$runId.'/finalize')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $run->refresh();
        $run->update(['status' => PayrollRunV2::STATUS_FINALIZED]);
        $payslipRes = $this->postJson('/api/v1/payslips/generate', [
            'payrollRunId' => $runId,
        ])->assertCreated();

        $payslipId = (int) $payslipRes->json('data.0.id');

        $run->update(['status' => PayrollRunV2::STATUS_CLOSED, 'closed_at' => now()]);

        $this->postJson('/api/v1/payslips/'.$payslipId.'/regenerate')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $run->refresh();
        $this->assertSame(PayrollRunV2::STATUS_CLOSED, $run->status);
    }
}
