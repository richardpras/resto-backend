<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollRunAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ClosedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\PayrollPostingAccountsFixture;
use Tests\TestCase;

class PayrollPostingAuditTest extends TestCase
{
    use ClosedPayrollRunFixture;
    use HrmApiFixture;
    use PayrollPostingAccountsFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedPayrollPostingAccounts();
    }

    public function test_posting_audit_records_created(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')->assertCreated();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/reverse-posting', ['notes' => 'Undo'])->assertOk();

        $actions = collect($this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/audit')->json('data'))
            ->pluck('action')
            ->all();

        $this->assertContains(PayrollRunAudit::ACTION_POSTING_CREATED, $actions);
        $this->assertContains(PayrollRunAudit::ACTION_POSTING_REVERSED, $actions);
    }
}
