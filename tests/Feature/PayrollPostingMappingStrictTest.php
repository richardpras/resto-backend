<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\AccountingPostingMappingService;
use App\Modules\HR\Services\PayrollPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ClosedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\PayrollPostingAccountsFixture;
use Tests\TestCase;

class PayrollPostingMappingStrictTest extends TestCase
{
    use ClosedPayrollRunFixture;
    use HrmApiFixture;
    use PayrollPostingAccountsFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_posting_fails_without_mappings(): void
    {
        $this->seedPayrollPostingAccounts();
        DB::table('accounting_posting_mappings')->where('module', 'payroll')->delete();

        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();

        $service = app(PayrollPostingService::class);
        $user = auth('api')->user();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);
        $service->post($user, (int) $run->id);
    }

    public function test_posting_succeeds_after_mappings_seeded(): void
    {
        $this->seedPayrollPostingAccounts();

        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();

        $this->postJson('/api/v1/payroll-runs-v2/'.(int) $run->id.'/post')
            ->assertCreated()
            ->assertJsonPath('data.postingStatus', 'posted');
    }
}
