<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\Concerns\ProcurementTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ProcurementReconciliationTest extends TestCase
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

    public function test_procurement_reconciliation_endpoint_returns_report(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/reconciliation/procurement')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'grni' => ['glBalance', 'subledger', 'difference', 'status'],
                    'inventory',
                    'accountsPayable',
                    'postedGrnTotal',
                    'postedInvoiceTotal',
                    'postedPaymentTotal',
                    'status',
                ],
            ])
            ->assertJsonPath('data.status', 'balanced');
    }
}
