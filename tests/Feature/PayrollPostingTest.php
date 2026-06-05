<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPosting;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ClosedPayrollRunFixture;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\PayrollPostingAccountsFixture;
use Tests\TestCase;

class PayrollPostingTest extends TestCase
{
    use ClosedPayrollRunFixture;
    use FinalizedPayrollRunFixture;
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

    public function test_preview_post_and_reverse(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();
        $runId = (int) $run->id;

        $preview = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/posting-preview')->assertOk();
        $this->assertTrue($preview->json('data.balanced'));
        $this->assertNotEmpty($preview->json('data.lines'));

        $post = $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')->assertCreated();
        $this->assertSame('posted', $post->json('data.postingStatus'));
        $this->assertNotNull($post->json('data.journalEntryId'));

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')->assertStatus(422);

        $reverse = $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/reverse-posting', [
            'notes' => 'Correction',
        ])->assertOk();
        $this->assertSame('reversed', $reverse->json('data.postingStatus'));

        $status = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/posting')->assertOk();
        $this->assertSame('reversed', $status->json('data.postingStatus'));
    }

    public function test_closed_run_required(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/posting-preview')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_duplicate_posting_blocked(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();
        $runId = (int) $run->id;

        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')->assertCreated();
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payrollRunId']);

        $this->assertSame(1, PayrollPosting::query()->where('payroll_run_id', $runId)->count());
    }
}
