<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\RendersPendingPayslips;
use Tests\TestCase;

class PayslipGenerationStatusTest extends TestCase
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

    public function test_generation_status_idle_before_generate(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->getJson('/api/v1/payslips/generation-status?payrollRunId='.$run->id)
            ->assertOk()
            ->assertJsonPath('data.phase', 'idle')
            ->assertJsonPath('data.total', 0);
    }

    public function test_generation_status_queued_then_completed(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();

        $this->postJson('/api/v1/payslips/generate', ['payrollRunId' => $run->id])->assertCreated();

        $this->getJson('/api/v1/payslips/generation-status?payrollRunId='.$run->id)
            ->assertOk()
            ->assertJsonPath('data.phase', 'queued')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.draft', 1);

        $this->renderPendingPayslipsForRun($run->id);

        $this->getJson('/api/v1/payslips/generation-status?payrollRunId='.$run->id)
            ->assertOk()
            ->assertJsonPath('data.phase', 'completed')
            ->assertJsonPath('data.generated', 1)
            ->assertJsonPath('data.percent', 100);
    }
}
