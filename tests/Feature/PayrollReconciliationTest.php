<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PayrollReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_payroll_reconciliation_endpoint_returns_report(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/reconciliation/payroll')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'payrollExpense' => ['glBalance', 'subledger', 'difference', 'status'],
                    'salaryPayable',
                    'pph21Payable',
                    'bpjsPayable',
                    'postedRunCount',
                    'status',
                ],
            ])
            ->assertJsonPath('data.status', 'balanced');
    }
}
