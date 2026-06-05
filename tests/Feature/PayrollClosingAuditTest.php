<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollRunAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollClosingAuditTest extends TestCase
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

    public function test_audit_trail_records_workflow_actions(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close', ['notes' => 'Done'])->assertOk();

        $auditRes = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/audit')->assertOk();
        $actions = collect($auditRes->json('data'))->pluck('action')->all();

        $this->assertContains(PayrollRunAudit::ACTION_CALCULATED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_APPROVED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_FINALIZED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_PAYMENT_STARTED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_PAYMENT_COMPLETED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_CLOSED, $actions);

        $closedEntry = collect($auditRes->json('data'))->firstWhere('action', PayrollRunAudit::ACTION_CLOSED);
        $this->assertSame('Done', $closedEntry['notes'] ?? null);
        $this->assertNotEmpty($closedEntry['performedBy']['name'] ?? null);
    }

    public function test_reopen_creates_audit_entry(): void
    {
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/mark-paid')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/close')->assertOk();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/reopen')->assertOk();

        $actions = collect($this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/audit')->json('data'))
            ->pluck('action')
            ->all();

        $this->assertContains(PayrollRunAudit::ACTION_REOPENED, $actions);
    }
}
