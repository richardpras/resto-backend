<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ProcurementTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountsPayableReconciliationTest extends TestCase
{
    use ProcurementTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedAccountingAccounts();
    }

    public function test_ap_reconciliation_returns_balanced_structure(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $response = $this->getJson('/api/v1/accounting/reconciliation/ap');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['subledger', 'glBalance', 'difference', 'status'],
            ])
            ->assertJsonPath('data.status', 'balanced');
    }
}
